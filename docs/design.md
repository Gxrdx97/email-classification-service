# Design — email-classification-service

## Contexto

Serviço autônomo, auto-hospedado e de código aberto (Apache-2.0). Uma instalação = uma conta Gmail. Não reutiliza o projeto RAG nem depende de agentes ou memória; inclui apenas recuperação híbrida de Exemplos de Revisões humanas com opt-in por Categoria. O classificador trabalha sobre Threads inteiras e Categorias criadas pelo Usuário; o fluxo de cotações foi removido completamente. Conteúdo de e-mail, Exemplos recuperados, respostas de provedores e sugestões de modelo são dados não confiáveis.

## Visão geral

```text
OAuth do Gmail no servidor com `gmail.modify` para projetar Labels de Categoria e `gmail.labels` para localizar/criar suas definições.
  → Sincronização (`list/get` + historyId incremental)
  → Captura canônica (mensagens/threads imutáveis)
  → Enriquecimento determinístico
  → Embedding de consulta local + busca lexical e semântica de Exemplos elegíveis
  → Gate de privacidade (DLP + prompt injection no payload completo)
  → Pré-análise Jev assíncrona (somente sugestão, opt-in)
  → Propostas consultivas de classificação (regras e/ou LLM)
  → Revisão humana escolhe/confirma/corrige e persiste a decisão oficial
  → categorized | uncategorized | inconclusive | failed
  → Herança para mensagens / reclassificação sob comando

Revisão humana corrigida + opt-in da Categoria → Exemplo versionado → embedding e índice local
```

## Decisões

### D1 — Contratos (portas)

| Contrato | Responsabilidade |
|---|---|
| `MailboxSource` | listar/obter/sincronizar mensagens; cursor por conta; sem operações de escrita |
| `GmailCategoryLabelGateway` | localizar/criar Labels de Categoria e alterar apenas associações de Labels de Categoria geridas pelo serviço |
| `CategoryLabelMappingRepository` | persistir o mapeamento estável entre Categoria e `labelId` do Gmail |
| `OAuthConnection` | connect/callback/refresh/revoke/validação de escopos |
| `MessageRepository` | persistir mensagens, threads e versões imutáveis |
| `EnrichmentProvider` | produzir fatos determinísticos versionados com proveniência |
| `PrivacyPolicy` | decidir o que pode ir a provedor externo (`redact`, `flag`, `abort`) |
| `ClassifierProvider` | produzir proposta consultiva para Revisão humana |
| `PreAnalysisProvider` | produzir sugestões estruturadas não autoritativas para uma thread |
| `CategoryRepository` | CRUD e versionamento de definições |
| `CategoryExampleRepository` | persistir Exemplos derivados de Revisões, proveniência e versão da Categoria |
| `ExampleRetriever` | combinar busca lexical PostgreSQL e busca semântica `pgvector` no escopo local |
| `LocalEmbeddingProvider` | gerar embeddings localmente, sem transmissão a provedores externos |
| `ClassificationRepository` | decisões oficiais por thread persistidas pela Revisão humana, estados, herança, revisões e histórico |
| `PreAnalysisRepository` | pré-análises, sugestões, proveniência de Exemplos recuperados e consentimentos de envio |

Todos os provedores e componentes de integração implementam interfaces neutras; fakes sintéticos são usados nos testes. O retorno de um modelo nunca autoriza acesso, tenant, conta, escopo ou side effect.

### D2 — Modelo de dados

**Projeção de Labels de Categoria no Gmail**

- `category_label_mappings` associa cada `category_id` ao `provider_label_id` estável da Label de Categoria do Gmail, incluindo o nome visível usado na criação/reutilização. A definição é criada ou reutilizada pelo nome exato; depois do vínculo, a sincronização usa o ID persistido e não renomeia nem apaga definições.
- `message_category_label_sync` registra, por mensagem e Categoria, o conjunto desejado, o último estado aplicado e a última falha de sincronização. Uma intenção/outbox durável referencia a conta, mensagem e versão da classificação; a chave idempotente impede duplicação em retries.
- Esse mapeamento e estado são distintos de `messages.labels`, que continua sendo o metadado vivo de Labels capturadas do Gmail. A projeção não cria uma nova versão do conteúdo da mensagem.

**Conta e conexão**

- `account`: provider, Google subject, scopes concedidos, estado, `last_history_id`, última sincronização.
- Refresh token cifrado; access token de curta duração em cache.

**Captura**

- `messages`: identidade canônica, provider message id, `thread_id`, subject, direção (`inbound|outbound`), data, labels, snippet, corpo bruto versionado, `content_hash`, headers relevantes, metadados de anexos (nome, media type, tamanho, sem conteúdo), recebido em.
- `message_threads`: provider thread id, assunto canônico, contagem, participantes.
- Imutabilidade: mudança de conteúdo cria nova Versão da mensagem; mudança somente de Labels atualiza metadado vivo.

**Categorias**

- `categories`: id, name, slug único por instalação, description, instruction opcional, version, archived_at e opt-in para Exemplos de Revisões.
- `category_versions`: snapshot imutável por mudança; classificação e sugestões referenciam a versão usada.
- `category_examples`: texto do Exemplo, instalação, Categoria e versão, Revisão de origem, proveniência, estado de elegibilidade, representação lexical PostgreSQL e embedding `pgvector`. Só Revisões humanas promovidas com opt-in podem ser indexadas; dimensões/modelo local ficam pendentes em `PD-011`.

**Classificação oficial**

- `classifications`: decisão oficial por `thread_id` e Estado operacional (`pending|categorized|uncategorized|inconclusive|failed`), categories, category versions, confidence, review_required, reason codes, evidence refs, proveniência humana da Revisão, run id, inconclusive reason e created at. Somente a Revisão humana persiste a decisão oficial; provider/model/version, quando registrados, descrevem a proposta consultiva e não sua autoria.
- `classification_runs`: batch id, modo, versões usadas, proveniência de provider/model das propostas e contadores; jobs sempre operam por thread e os runs não persistem decisões oficiais.
- `reviews`: correção humana com alvo thread ou mensagem; divergência individual fica registrada e pode virar Exemplo somente com opt-in da Categoria.

**Pré-análise**

- `pre_analyses`: thread, snapshot hash, versão do catálogo, provider/model, pergunta, versão da política, estado da pré-análise, consentimento por thread quando necessário, usage e IDs/versões dos Exemplos recuperados (sem copiar o texto para a proveniência).
- `category_suggestions`: sugestões ligadas a uma pré-análise e a uma versão de Categoria, com probabilidade, incerteza e indicadores de conflito; nunca são categorias oficiais.

**Enriquecimento**

- `enrichment_facts`: thread/message, kind (por exemplo `language`, `participants_normalized`, `quoted_reply_stripped`, `signature_detected`, `thread_position`), payload JSON, proveniência determinística, versão do extrator e janela válida.
- Campos frequentemente consultados ficam relacionais; JSON somente para detalhes não operacionais.

**Sync**

- `sync_state`: um registro por conta, sincronização completa concluída, `history_id`, cursor de paginação, última execução e erros tipados com backoff.

### D3 — Sincronização Gmail

- Carga inicial: `users.messages.list` paginado e `users.messages.get` com `format=full`, respeitando limites de taxa e backoff.
- Incremental: `users.history.list` a partir de `history_id` armazenado; Labels determinam pertencimento a `INBOX`/`SENT`/arquivo.
- Se `historyId` não for válido, resync completo idempotente deduplica por account + provider message id.
- Polling agendado; Pub/Sub `watch` fica como otimização futura.
- O Cursor avança somente depois da persistência confirmada. A conclusão do sync dispara pré-análise assíncrona sem esperar o provedor externo somente quando Jev está habilitado; `pre_analysis.provider=none` não enfileira `PreAnalyzeThreadJob`.

### D4 — Enriquecimento determinístico

Pipeline por mensagem, com ordem fixa e cada etapa versionada:

1. Análise MIME.
2. HTML → texto com preservação de estrutura mínima; alternativa `text/plain`.
3. Remoção de respostas citadas e assinaturas.
4. Normalização de Participantes e direção a partir de headers e Labels.
5. Idioma, com desconhecido como resultado explícito.
6. Correlação por `threadId` + `Message-ID`/`In-Reply-To`/`References`.
7. Metadados de anexos, sem extração de conteúdo.

Cada fato grava `extractor_version`; nada probabilístico entra nesta camada. A entrada do classificador e da pré-análise agrega todas as mensagens da Thread, com truncação versionada quando necessária.

### D5 — Privacidade e DLP

- Gate obrigatório antes de qualquer envio a LLM, Jev ou outro provedor externo.
- Padrões configuráveis de PII e segredos produzem `redact`, `flag` ou `abort`.
- Detecção local de prompt injection marca a Thread; conteúdo sinalizado nunca é enviado cru.
- `flag` exige consentimento explícito para a Thread antes da chamada externa.
- O Gate examina o payload completo a enviar ao Jev, incluindo a Thread e os Exemplos recuperados; exemplos também são entrada não confiável.
- Política do Usuário define provedores habilitados, redaction, retenção, wipe e termos/residência aceitos.
- Embeddings e Exemplos são dados derivados sensíveis: podem permanecer apenas no armazenamento PostgreSQL autorizado, nunca em logs, erros ou respostas da API; retenção, opt-out e wipe os removem ou invalidam.
- O gerador de embeddings é local e não transmite conteúdo a provedores externos. Tokens, corpos brutos, e-mails reais e respostas brutas de provedores nunca entram em logs, fixtures, specs ou erros.

### D6 — Classificação oficial

**Entrada**: texto enriquecido de toda a Thread, metadados e Categorias ativas com id, nome, descrição, exemplos e instruction.

**Proposta consultiva validada**:

```text
categories[]                 // somente IDs existentes e ativos
confidence: 0..1
review_required: bool
reason_codes[]
evidence_refs[]              // somente referências locais verificadas
```

**Implementações**:

- `RuleBasedClassifier`: gera proposta consultiva determinística e local; é o primeiro provedor a rodar.
- `LlmClassifierProvider`: structured output validado como proposta consultiva; ID desconhecido é rejeitado; vazio explícito pode propor `uncategorized`; abstenção pode propor `inconclusive` com reason.
- Modelo e configuração são explícitos; nenhum alias mutável é a identidade histórica de uma execução.

**Estados operacionais e decisão oficial**:

```text
pending        → ainda não processado ou aguardando Revisão humana
categorized    → Revisão humana persistiu uma ou mais categorias
uncategorized  → Revisão humana persistiu a ausência de categoria aplicável
inconclusive   → Revisão humana persistiu abstenção com inconclusive_reason obrigatório
failed         → erro técnico após retries; estado operacional, não decisão classificatória, e não substitui decisão oficial
```

Somente a Revisão humana escolhe, confirma ou corrige e persiste o resultado oficial. `RuleBasedClassifier`,
`LlmClassifierProvider` e Jev produzem propostas consultivas e não atravessam essa fronteira; `ecs:classify`
não pode persistir por conta própria. Falha de provedor pode marcar sua tentativa/job como `failed`, mas
não substitui nem sobrescreve uma decisão oficial e mantém a Revisão manual disponível.

### D7 — Pré-análise Jev

- `PreAnalysisProvider` chama o OpenRouter somente depois do Gate e do opt-in; `OPENROUTER_MODEL` é obrigatório e não há seleção automática de modelo. A integração usa HTTP, sem SDK específico do provedor; o cliente HTTP concreto será selecionado e fixado pelo implementador durante o bootstrap, após validar a documentação oficial.
- A pergunta usa uma decisão fechada por Categoria ativa, preservando probabilidades individuais e permitindo múltiplas sugestões; `none_applies` é um sinal separado. Exemplos recuperados entram somente como contexto de inferência e após o Gate avaliar o payload completo.
- O agregador local valida allowlist, intervalo `0..1`, versão da Categoria, model, question/schema, policy version e usage.
- O Jev não produz evidência verificada e não pode escolher conta, tenant, autorização ou side effect.
- Exemplos recuperados são referências não confiáveis, limitadas às Categorias ativas com opt-in; seu uso não altera pesos do modelo e não constitui fine-tuning.
- A sugestão fica separada do Estado operacional; baixa confiança, conflito ou falha deixam a Revisão manual disponível. Falha do provedor não substitui decisão oficial nem altera os Labels que ela governa.
- Automaticamente, somente Threads novas ou pendentes sem resultado oficial anterior são elegíveis quando
  Jev está habilitado. `pre_analysis.provider=none` não cria job. Thread com resultado oficial não recebe
  nova pré-análise após mensagem nova.

### D8 — Jobs e execução

`SyncCategoryLabelsJob` processa intenções persistidas depois do commit do resultado oficial. A reconciliação é idempotente e pode ser repetida; a transação local não permanece aberta durante chamadas ao Gmail. Falhas mantêm a classificação oficial e a intenção pendente, com tentativa/status observável separado.

- Redis é obrigatório para fila, worker e agendador no runtime Docker auto-hospedado.
- Jobs: `SyncAccountJob`, `BackfillJob`, `EnrichMessageJob`, `PreAnalyzeThreadJob`, `ClassifyThreadJob`, `ReclassifyBatchJob`, `IndexCategoryExampleJob`, `SyncCategoryLabelsJob`, `RetentionSweepJob`.
- O sync confirma captura e Cursor antes de enfileirar `PreAnalyzeThreadJob`; o worker não bloqueia o sync.
- Idempotência da pré-análise: `(account_id, thread_id, thread_snapshot_hash, category_catalog_version, provider_model, privacy_policy_version)`.
- Classificação oficial e reclassificação operam por Thread; reclassificação só ocorre sob comando do Usuário e preserva resultados com proveniência humana.
- A promoção de Exemplo após Revisão ocorre somente com opt-in da Categoria; a geração local do embedding/índice é enfileirada depois do commit e é idempotente.
- Agendador interno dispara sincronização periódica.

### D9 — API/CLI

A CLI também expõe `ecs:labels:reconcile [--thread=<id>|--all]` para reconciliar associações de Labels de Categoria a partir das decisões oficiais e do estado persistido. Revisões oficiais criam a intenção após commit; não é necessária uma rota HTTP pública nova.

- **CLI** (fluxo primário):
  - `ecs:connect`
  - `ecs:sync [--full|--incremental]`
  - `ecs:enrich`
  - `ecs:review:list` e `ecs:review:set`
  - `ecs:classify [--all|--since=...]` — gera propostas consultivas; não persiste resultado oficial nem aplica Sugestões do Jev. Somente `ecs:review` com ação humana escolhe/confirma/corrige e persiste a decisão.
  - `ecs:categories:list|create|update|archive`
  - `ecs:reclassify`
  - retenção e wipe
- **HTTP obrigatório**: API para sync, threads, sugestões, categorias, consentimento e Revisão, publicada na VPS por Nginx com HTTPS/TLS. O Bearer usa Google OIDC, separado do OAuth Gmail; middleware server-side valida issuer, audience, claims temporais, expiração, assinatura, discovery/JWKS, rotação de chaves e allowlist de identidades. Nunca expõe corpo bruto por padrão.

### D10 — Segurança

A capacidade de escrita fica isolada em `GmailCategoryLabelGateway`: ele aceita somente IDs de Labels de Categoria customizadas presentes no mapeamento da conta e operações de adicionar/remover associação. A autorização é derivada do estado oficial persistido, nunca da saída de Jev/LLM; `INBOX` e Labels não geridas não entram no conjunto removido. O adaptador não expõe métodos de envio, arquivamento ou exclusão.

- OAuth state no servidor, de uso único, associado à instalação; redirect fixo.
- Escopos solicitados e validados: `gmail.modify` para modificar Labels em Messages e `gmail.labels` para listar/criar definições; rejeitar `mail.google.com`.
- Refresh token cifrado, nunca logado; segredos somente por env/secret store.
- Todas as escritas passam por repositórios; camadas superiores não acessam DB diretamente.
- Conteúdo de e-mail e modelo é dado não confiável. A autorização vem do contexto server-side, não da saída externa.
- Respostas, cache, evidências e serialização usam allowlist de Categoria, sanitização e provenance verificada.
- Serviços com estado de request usam escopo apropriado; nenhum estado de conta fica em static ou singleton persistente.
- A API exige Google OIDC válido; token estático compartilhado não é uma alternativa.

### D11 — Configuração (`config.yaml` + env)

- `gmail`: client id/secret, scopes fixos (`gmail.modify` e `gmail.labels`), redirect URI, polling interval, habilitação do escritor restrito e política de retry/reconciliação.
- `sync`: janela da carga inicial e tamanho da página.
- `privacy`: provedores externos habilitados (padrão: nenhum), regras de redaction, `redact|flag|abort`, consentimento por thread, dias de retenção e wipe.
- `classifier`: implementation, provider/model, threshold e inconclusive threshold.
- `pre_analysis`: provider (`jev|none`), model version, question/schema version, category threshold, retry/backoff e escopo automático.
- `openrouter`: endpoint, credencial e `OPENROUTER_MODEL` obrigatório; nenhum modelo é selecionado automaticamente.
- `queue`: Redis e configuração do worker/agendador.
- `storage`: PostgreSQL obrigatório no runtime suportado.
- Recuperação lexical usa full-text search PostgreSQL; busca semântica usa `pgvector`. Runtime/modelo/dimensão de embedding, fusão, top-k e comportamento de falha permanecem em `PD-011`.
- `api`: issuer, audience/client, discovery/JWKS, allowlist de identidades e configuração interna da API; TLS e proxy são operacionais.

### D12 — Testes Pest e conteúdo não confiável

Os fakes Gmail também cobrem scopes `gmail.modify` + `gmail.labels`, criação/reuso por nome e persistência do ID, rejeição de IDs desconhecidos/de sistema, herança Thread versus correção Message-only, preservação de `INBOX`/Labels alheias e falha/retry idempotente. As asserções garantem que nenhum envio, arquivamento ou exclusão é solicitado.

- Ambiente de execução dos testes: Laravel/PHP + Pest; o implementador seleciona e fixa as versões de PHP/Laravel e dependências durante o bootstrap, após validar a documentação oficial.
- Profile Spec Lean: `standard`; cada decisão tem prova no seam público e no seam de decisão quando atravessa uma fronteira.
- Unitário/decisão: MIME, remoção de quoted-reply/signature, Participantes, idioma, Cursor, Gate, allowlist, probabilidades, estados e transições.
- Feature/integração: Gmail fake + Categorias sintéticas, persistência por Thread, fila após Cursor, Revisão thread/mensagem, reclassificação adesiva, OAuth fake e resync.
- Testes adversariais da recuperação: prompt injection no corpo ou em Exemplo, instruções na saída de modelo, Categoria/conta/escopo inventado, evidence ref sem provenance, DLP/redaction, ausência de corpo/token/PII em logs e isolamento de conta.
- Evals com opt-in: fixtures sintéticas `.eml` + holdout; scorer valida IDs contra Categorias do fixture; nenhum provedor real na suíte normal.
- Provas de recuperação verificam opt-in/proveniência, geração local, canais lexical e semântico, isolamento por instalação/Categoria, Gate do payload completo, ciclo de vida dos índices e ausência de fine-tuning.
- Adaptadores reais de Gmail e OpenRouter ficam configuráveis desde o bootstrap, mas chamadas reais só são permitidas em smoke/evals explicitamente opt-in, com segredos fora do repositório.
- Plugins de mutation, architecture, type/coverage, snapshot, stress e browser entram conforme o seam existir; não se inventa teste de browser para uma CLI sem interface.

### D13 — Recuperação híbrida de Exemplos

- PostgreSQL full-text search e `pgvector` recuperam Exemplos de Revisões humanas promovidos com opt-in por Categoria; somente Categorias ativas e o escopo da instalação participam.
- Embeddings de consulta e de Exemplos são gerados localmente. Exemplos são versionados com IDs da Revisão de origem e da Categoria; a Pré-análise guarda somente IDs/versões recuperados como proveniência.
- O conteúdo recuperado é não confiável. Antes de enviá-lo ao Jev, o Gate aplica DLP e detecção de prompt injection à representação completa da Thread junto dos Exemplos.
- Exemplos e vetores são derivados sensíveis, nunca expostos pela API ou logs, e seguem retenção, exclusão por opt-out, arquivamento e wipe. Não há ajuste de pesos nem fine-tuning nesta fase.
- Runtime/modelo/dimensão, fusão, top-k e comportamento quando um canal falha permanecem pendentes em `PD-011`.

### D14 — Projeção de Labels de Categoria no Gmail

Labels de Categoria são uma projeção externa de decisões oficiais revisadas; nunca são fonte de verdade da classificação. A definição de Gmail é customizada, usa o nome visível da Categoria e é localizada/reutilizada por nome exato ou criada quando ausente. O serviço persiste o `labelId` estável associado à Categoria. Não renomeia nem apaga definições de Labels e não altera Labels que não estejam no mapeamento gerido.

O limite de captura continua em `MailboxSource` e permanece somente leitura. A escrita usa um gateway separado que aceita apenas `labelId`s customizados presentes no mapeamento gerido e operações de adicionar/remover associações em uma Message. Usa `users.messages.modify` por Message para preservar correções restritas a uma Message; não usa `threads.modify`. Nunca remove `INBOX`, não altera Labels alheios e não envia, arquiva ou apaga mensagens.

Para cada Message, o conjunto desejado deriva da revisão oficial mais recente restrita à própria Message, quando existir; caso contrário, herda o resultado oficial mais recente da Thread. Um resultado oficial `categorized` aplica as Labels correspondentes às Categorias existentes. `uncategorized` remove somente associações de Labels de Categoria geridas pelo serviço. `pending`, `inconclusive`, `failed` e Sugestões de Categoria da Pré-análise não iniciam nova decisão ou alteração de Labels. Uma Message nova pode tornar a Thread operacionalmente `pending` sem reclassificá-la; a última decisão oficial continua governando os Labels herdados até ação explícita da Revisão humana.

Uma revisão, correção ou reclassificação oficial persiste primeiro a decisão e uma intenção durável de reconciliação na mesma fronteira transacional; um job pós-commit aplica a diferença idempotente entre o conjunto desejado e as associações observadas. Chamadas Gmail nunca ocorrem dentro de uma transação de banco. Remoção manual de uma associação gerida é restaurada na próxima reconciliação. Falha/retry da integração não desfaz o resultado oficial local: o estado da projeção permanece pendente ou falho, com retry limitado e observável.

Comando operacional: `ecs:labels:reconcile [--thread=<id>|--all]`. Ele reconcilia somente associações geridas; não adiciona rota HTTP pública. A credencial de escrita requer `gmail.modify` e `gmail.labels`; o adaptador recusa `mail.google.com`, IDs de sistema e IDs fora do mapeamento permitido.

### Status das decisões e pendências de implementação

- `PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
  bootstrap. Isso não significa que testes/checks passaram nem que a atividade
  de `PD-009` foi concluída; também não cria o `validate_checks.py`, que continua
  ausente.
- `PD-011`: selecionar e validar runtime/modelo/dimensão de embeddings, fusão
  lexical-semântica, top-k e comportamento limitado de falha dos canais.

O bootstrap selecionará e fixará versões e pacotes compatíveis depois da
validação da documentação oficial. Essa seleção é uma tarefa do implementador,
não uma escolha automática em runtime.

### Pendências de implementação e operação

- Implementação: selecionar e fixar versões exatas de PHP/Laravel, clientes
  Google/Gmail e OAuth/OIDC e cliente HTTP do OpenRouter; implementar o
  middleware e a configuração final de audience/allowlist OIDC. Nginx já está
  definido como proxy reverso.
- Recuperação: resolver `PD-011` durante a implementação sem alterar a geração
  local, a proveniência ou a exigência de opt-in por Categoria.
- Sequenciamento/tooling: `PD-009` e `PD-010` estão permanentemente dispensadas
  como blockers; essa dispensa não certifica checks nem cria o validator ausente.
- Operação: credenciais Gmail OAuth, cliente Google OIDC, domínio/DNS/TLS,
  credenciais OpenRouter e `OPENROUTER_MODEL`, segredos da VPS e plano de
  armazenamento/backups.
- Governança: termos, residência, retenção e subprocessadores dos provedores
  externos antes de habilitar dados reais.
