# Pesquisa — email-classification-service

## Contexto

Evolução da change `email-quote-classification` (repo `rag`). O escopo mudou três vezes até estabilizar:

1. Classificação quote-cêntrica sobre o RAG (original).
2. Classificação genérica de qualquer e-mail (reframe).
3. **Serviço autônomo de código aberto, auto-hospedado, com categorias definidas pelo usuário** (estado final).

O código e o desenho do RAG original (ingestão, AGE, tenancy DB-per-tenant, Horizon e agents de busca) **não são reaproveitáveis** — o serviço nasce do zero. O produto, porém, inclui uma capacidade nova e restrita de recuperação híbrida de Exemplos de Revisões humanas opt-in: PostgreSQL full-text e `pgvector`, com embeddings gerados localmente.

## Descobertas sobre o ambiente de origem

- Não existia implementação de e-mail no repo `rag`: nenhuma integração Gmail/IMAP, nenhuma migração, nenhuma tabela de mensagens.
- `config/mail.php` é apenas mailer de saída Laravel; `config/services.php` não tem Google.
- `composer.json` não tem Google API client nem cliente IMAP.
- Dezancy existentes (middleware de tenant fail-open, fallback `tenant_id = domain_id`) eram inseguras para e-mail privado e reforçam a decisão de não reaproveitar o código.
- O harness de evals (`EmailClassificationOutputScorer`) era útil como conceito (structured output, códigos de razão, proibição de chaves sensíveis) mas a taxonomia `positive|negative|review` é descartada.

## Gmail: API versus IMAP

**Decisão: Gmail REST API com OAuth 2.0 server-side para captura e projeção restrita de Labels de Categoria.**

| Aspecto | Gmail API | IMAP XOAUTH2 |
|---|---|---|
| Escopos OAuth | `gmail.modify` para alterar Labels em mensagens e `gmail.labels` para listar/criar definições | `https://mail.google.com/` (amplíssimo; não solicitar) |
| Threading | `threadId` nativo | Reconstrução por headers |
| Incremental | `historyId` + `history.list` | UID/UIDVALIDITY por pasta |
| Labels | Nativos; Label de Categoria personalizado sincronizado após Revisão oficial | Mapeados como pastas/X-GM-LABELS |
| Verificação Google | Requerida p/ escopo restrito | Igual |

- `gmail.metadata` é insuficiente (não expõe corpo). `gmail.modify` permite a captura e a alteração de Labels exigida; `gmail.labels` cobre a gestão de definições. A aplicação limita chamadas e IDs de Label permitidos; nenhum caminho arquiva, apaga ou envia mensagens.
- Senha de app / senha simples **não** é opção aceitável.
- `watch` (Pub/Sub) existe mas exige renovação ≤7 dias; polling agendado primeiro.

**Projeção restrita de Labels de Categoria:** criar ou reutilizar um Label
personalizado pelo nome visível da Categoria e persistir o mapeamento para o ID
estável retornado pelo Gmail. `users.messages.modify` recebe somente IDs de
Labels de Categoria geridos; usar a operação por mensagem preserva correções
restritas a uma Mensagem. A projeção Thread→mensagens inclui mensagens atuais e
futuras herdadas, sem reclassificação automática. Uma nova Message pode tornar a
Thread operacionalmente `pending`, enquanto a última decisão oficial continua
governando seus Labels herdados até ação explícita da Revisão humana. A
reconciliação restaura uma associação gerida removida manualmente e altera
somente associações do serviço; não remove `INBOX`, Labels alheios ou definições
de Label.

**Fontes Gmail consultadas para escrita:**

- https://developers.google.com/workspace/gmail/api/guides/labels
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/modify
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.threads/modify
- https://developers.google.com/workspace/gmail/api/auth/scopes

**Fontes consultadas:**
- https://developers.google.com/workspace/gmail/api/auth/web-server
- https://developers.google.com/workspace/gmail/api/auth/scopes
- https://developers.google.com/workspace/gmail/api/guides/sync
- https://developers.google.com/workspace/gmail/api/guides/push
- https://developers.google.com/workspace/gmail/imap/xoauth2-protocol

## Semântica de cursor

- Gmail expõe **um** `historyId` por caixa postal (conta), não por pasta.
- `INBOX`/`SENT` são **labels**; filtra-se por label, não por caixa física.
- Se o `historyId` expirar (retenção limitada de histórico), requer resync completo.
- A change antiga exigia cursores independentes por INBOX/SENT — premissa inválida para Gmail; corrigida no design.

## Categorias definidas pelo usuário

- Requisito do usuário: **criar quais categorias quiser** — taxonomia é dado, não enum.
- Duas situações sem Categoria atribuída são estados operacionais distintos:
  - `uncategorized`: processado, nenhuma categoria aplicável.
  - `inconclusive`: a proposta recomenda abstenção (baixa confiança, conflito, sem match, conteúdo insuficiente); o resultado oficial ainda depende da Revisão humana.
- Classificadores recebem as Categorias ativas e só podem propor IDs existentes; somente a Revisão humana escolhe, confirma/corrige e persiste resultado oficial.
- Definições versionadas: editar descrição/exemplos não reescreve o histórico de classificações anteriores.

## Provedores de classificação

- Requisito do Usuário: **poder enviar a provedores externos** — provedores configuráveis; a integração real do Jev usa OpenRouter.
- Opt-in pelo Usuário; o padrão pode ser processamento local/regras.
- Conteúdo de e-mail é não confiável: DLP + detecção de prompt injection **antes** de qualquer chamada externa.
- E-mails reais nunca entram no repositório: fixtures sintéticas `.eml` e holdouts gerados.

## Jev como pré-análise

- Escopo decidido: Jev é um `PreAnalysisProvider` opcional e consultivo; nunca escolhe ou persiste resultado oficial. Somente a Revisão humana escolhe, confirma/corrige e persiste a decisão oficial.
- Após cada sync, quando Jev está habilitado, uma tarefa assíncrona analisa somente Threads novas ou pendentes sem resultado oficial anterior. Com `provider=none`, nenhum job de pré-análise é criado. Threads com resultado oficial não são pré-analisadas automaticamente quando recebem nova mensagem.
- A saída é uma Sugestão de categoria por Categoria ativa, com probabilidade individual, incerteza e provenance de modelo/pergunta/política. Sugestões não mutam Estado operacional nem persistem resultado oficial; falha do provedor não substitui uma decisão oficial nem elimina a Revisão manual.
- A integração inicial usa HTTP para evitar acoplamento a SDK específico. `OPENROUTER_MODEL` é obrigatório e não pode ser escolhido automaticamente; o modelo e a pergunta devem ser versionados, e alias mutável não é identidade histórica. O cliente HTTP concreto será selecionado e fixado pelo implementador durante o bootstrap, após validação da documentação oficial.
- O endpoint, request shape e resposta tipada devem ser verificados novamente na implementação contra a documentação oficial atual do provedor. O contrato externo não substitui a validação local de schema, allowlist e intervalo `0..1`.
- O Gate continua local e anterior ao Jev. `redact` envia somente a representação permitida; `flag` exige consentimento por Thread; `abort` não faz chamada.
- Exemplos de Revisões humanas podem ser recuperados somente com opt-in por Categoria ativa; a busca é local e seus resultados são contexto de inferência, não treinamento nem autoridade.
- O Gate avalia a Thread e os Exemplos recuperados como payload completo antes de qualquer envio externo; dados derivados não aparecem em logs/respostas e seguem retenção, opt-out e wipe.
- Não há fine-tuning ou atualização de pesos nesta entrega. Runtime/modelo/dimensão, fusão, top-k e falha limitada dos canais permanecem pendentes em `PD-011`.
- Termos de retenção, residência nos EUA, subprocessadores e opção de zero data retention precisam de revisão do Usuário antes de habilitar e-mails reais.

**Fontes Jev consultadas:**

- https://typesafe.ai/blog/introducing-system-one-models-and-jev
- https://docs.typesafe.ai/ (endpoint System One, respostas estruturadas e limites publicados; confirmar versão no build)

## Segurança / privacidade

- Tokens OAuth: refresh token cifrado em repouso, nunca logado.
- Escopos solicitados: `gmail.modify` e `gmail.labels`; nunca solicitar `mail.google.com`. `gmail.modify` é mais amplo que a capacidade de negócio desejada, então a aplicação deve restringir a allowlist de métodos e IDs a Labels de Categoria próprios.
- Consentimento interativo obrigatório (conta pessoal; domain-wide delegation não se aplica).
- Verificação do app Google (escopo restrito) é pré-requisito de publicação, não de desenvolvimento local (modo testing).
- Retenção configurável de corpos brutos; exclusão real de dados a pedido do Usuário.

## Pilha tecnológica

- Decisão: **Laravel + Pest** é o runtime e harness de testes; PostgreSQL, Redis e Docker são o baseline de execução.
- O implementador selecionará e fixará, durante o bootstrap e após validar a documentação oficial, as versões exatas de PHP/Laravel, os clientes Google/Gmail e OAuth/OIDC e o cliente HTTP do OpenRouter. Não há seleção automática em runtime.
- **Nginx** é o proxy reverso definido para a VPS; o registro do cliente/audience OIDC e a allowlist de identidades continuam pendentes de operação.
- A API é obrigatória na VPS, atrás de proxy HTTPS/TLS, com Bearer Google OIDC distinto do OAuth Gmail; issuer, audience, claims temporais, assinatura, discovery/JWKS, rotação e allowlist devem ser validados server-side.
- Fila e agendador usam Redis; SQLite e fila em banco não são alternativas do runtime suportado.

### Status de PD-009 e PD-010

`PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
bootstrap. Isso não significa que testes ou checks passaram, não afirma que a
atividade preparatória de `PD-009` foi concluída e não cria o
`validate_checks.py` referido pelos checks, que continua ausente.

### Pré-requisitos operacionais

Credenciais Gmail OAuth, cliente/audience Google OIDC, identidade autorizada,
domínio/DNS/TLS, credenciais OpenRouter e `OPENROUTER_MODEL`, segredos da VPS,
armazenamento/backups e análise de termos, residência, retenção e
subprocessadores dos provedores precisam ser providenciados antes do uso com
dados reais.

## Testes

- O profile do Spec Lean é `standard`.
- Pest deve testar comportamento nos seams públicos, com fakes para Gmail, OAuth, Gate e providers.
- Os adaptadores reais de Gmail e OpenRouter ficam configuráveis desde o bootstrap, mas chamadas reais só ocorrem em smoke/evals explicitamente opt-in, fora da suíte normal.
- Casos adversariais cobrem prompt injection, output de modelo sem autoridade, allowlist de Categorias, evidence provenance, DLP/redaction, logs sanitizados, retries, concorrência do Cursor e isolamento single-tenant.
- Evals externos são opt-in, usam fixtures sintéticas em português e não rodam na suíte normal.

## Fases recomendadas

1. Contratos + fake source + fixtures `.eml` sintéticas.
2. OAuth Gmail (conect, callback, revoke, refresh) + sync completo/incremental.
3. Captura canônica + enriquecimento determinístico.
4. DLP/política de privacidade + provedores de classificação.
5. Categorias versionadas + estados reservados + revisão/reclassificação.
6. CLI/HTTP + observabilidade + retenção.
7. Evals opt-in com fixtures sintéticas.
