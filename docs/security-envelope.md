# Envelope de segurança — email-classification-service

## Limites de confiança

1. Gmail, corpos de mensagens, headers, Labels e metadados de anexos são entradas não confiáveis.
2. Fatos de enriquecimento são dados derivados localmente, mas o texto continua não confiável ao atravessar a fronteira de um provedor.
3. Jev, LLMs, respostas HTTP, valores de cache e saída de modelo são dados não confiáveis, nunca instruções nem autorização.
4. O contexto da instalação no servidor é a única fonte para identidade da conta, escopo, autorização e allowlist de Categorias.
5. A fronteira do provedor externo só é atravessada pelo Gate de privacidade e quando a política configurada permite.
6. A evidência retornada ao cliente deve apontar para proveniência verificada localmente; um modelo não pode inventar nem autorizar uma referência de evidência.

## Ameaças

1. **Token/conta comprometida** — refresh token vazado via logs, dumps ou repositório.
2. **Escopo/efeito excessivo** — o OAuth `gmail.modify` permite mais capacidade que a necessária; uma chamada ou ID indevido poderia enviar, arquivar, apagar ou alterar Labels alheios.
3. **Envio indevido a provedor externo** — PII/segredos/injeção enviados sem opt-in, redaction ou consentimento da Thread.
4. **Prompt injection** — conteúdo do e-mail ou resposta do modelo tentando alterar instruções, conta, tenant, escopo ou side effect.
5. **Vazamento de dados reais no projeto** — e-mails, tokens, prompts, respostas ou fixtures pessoais commitados.
6. **Estado retido indefinidamente** — corpo bruto, request externo ou payload de provedor sem expiração/wipe.
7. **Duplicação/perda no sync** — Cursor avançado antes da captura ou jobs duplicados após retry.
8. **Cross-request leak** — estado da conta, modelo ou política em singleton/static sob servidor long-lived.
9. **Classificador inventando categorias** — IDs fora da allowlist ou Categoria arquivada corrompendo dados.
10. **Pré-análise confundida com autoridade** — sugestão Jev alterando Estado operacional sem Revisão humana.
11. **Lavagem de evidência** — referência produzida por modelo tratada como evidência sem proveniência local.
12. **Replay/errata do OAuth** — state reutilizado, redirect aberto, callback spoofado ou escopo não validado.
13. **Exposição/uso indevido de Exemplos recuperados** — vetores ou texto de Revisões fora do escopo da instalação/Categoria, prompt injection recuperada ou retenção após opt-out/wipe.

## Controles obrigatórios

- **OAuth**: solicitar e validar `gmail.modify` e `gmail.labels`; nunca solicitar `mail.google.com`. State no servidor de uso único; redirect URI fixo; refresh token cifrado em repouso; acesso via env/secret store; revogação suportada.
- **Allowlist de escrita Gmail**: manter `MailboxSource` somente de leitura e expor uma porta separada, sem operações genéricas, para criar/reutilizar definições e adicionar/remover IDs de Labels de Categoria mapeados. Não implementar envio, arquivamento ou exclusão de mensagens; nunca incluir `INBOX` em IDs removidos. Labels alheios e definições existentes fora do mapeamento não podem ser alterados.
- **Autoridade da projeção**: somente uma decisão oficial com proveniência de Revisão humana pode iniciar sincronização; saída Jev/LLM, proposta, conteúdo de mensagem ou dado recuperado não autorizam efeitos. `uncategorized` remove somente associações de Categoria geridas; `pending`, `inconclusive` e `failed` não iniciam nova decisão. Uma nova Mensagem pode tornar a Thread operacionalmente `pending`, enquanto a última decisão oficial continua governando os Labels herdados até ação explícita da Revisão humana, sem reclassificação automática.
- **Reconciliação e auditoria**: mapear Categoria→ID do Label Gmail e registrar intenção/resultado por Mensagem. Jobs são idempotentes e retryáveis; remoção manual de uma associação gerida é restaurada na próxima reconciliação. Falha de sync não desfaz a classificação oficial nem é reportada como sucesso no Gmail.
- **Cursor de sync**: `history_id` só avança após persistência confirmada; falha tipada com backoff; resync completo quando histórico expira.
- **Idempotência**: unique account + provider message id; hash de conteúdo; pré-análise por snapshot, catálogo, modelo e política; classificação oficial por Thread. O job de Jev só é criado quando o provider está habilitado e a Thread não tem resultado oficial anterior; `provider=none` não cria job.
- **Gate de privacidade**: antes de qualquer chamada externa, DLP + detector local de prompt injection; ação `redact|flag|abort`; provedor desabilitado por padrão. O Gate inspeciona o payload completo da Thread e dos Exemplos que serão enviados ao Jev.
- **Padrão do Gate**: a ação padrão é `abort`; dado sensível ou prompt injection detectado não envia a Thread e registra somente um evento sanitizado.
- **Consentimento `flag`**: uma Thread marcada precisa de decisão explícita antes da transmissão; opt-in global sozinho não libera essa Thread.
- **Redaction**: o request enviado contém somente a representação redigida permitida pela política, incluindo Exemplos recuperados; anexos nunca atravessam a fronteira.
- **Allowlist de Categorias**: resposta externa aceita somente IDs de Categorias ativas fornecidos pelo servidor; IDs desconhecidos/arquivados são descartados antes da persistência como proposta, nunca como resultado oficial.
- **Separação de autoridade**: Pré-análise, Sugestões e saídas de qualquer classificador não podem escolher ou persistir resultado oficial, Estado operacional, Herança, identidade de conta, escopo, autorização ou side effect. Somente a Revisão humana persiste a decisão oficial.
- **Proveniência da evidência**: `evidence_refs[]` são aceitos somente quando apontam para fatos ou metadados locais verificados; modelos não são fonte de prova.
- **Exemplos e embeddings**: somente Revisões humanas promovidas por opt-in da Categoria alimentam índices locais PostgreSQL full-text/`pgvector`; geração de embedding não transmite dados externamente. Texto e vetores são derivados sensíveis, sempre limitados à instalação e Categorias ativas/autorizadas, não são expostos por API/logs e obedecem a opt-out, retenção, arquivamento e wipe.
- **Logs e erros**: sem corpo, token, PII, request bruto, resposta bruta, embedding ou conteúdo de fixture; razão e metadados não sensíveis são permitidos.
- **Retenção**: `retention_days` para corpo bruto; request/response externo não é armazenado como texto bruto; sweep e wipe total são obrigatórios.
- **Stateless/scoped**: serviços com estado por request são scoped; nada de estado de conta/modelo em static ou singleton persistente.
- **API na VPS**: Nginx termina HTTPS/TLS e encaminha para o container Laravel; Bearer exige Google OIDC válido, separado do OAuth Gmail, com validação de issuer, audience, claims temporais, expiração, assinatura, discovery/JWKS, rotação de chaves e allowlist server-side.
- **Dados reais**: proibidos no repositório; fixtures sintéticas e holdouts gerados somente.

## Jev e provedores externos

- Jev é um `PreAnalysisProvider` consultivo; assim como os demais classificadores, não tem autoridade para escolher ou persistir resultado oficial.
- `PD-012` está resolvida: somente a Revisão humana escolhe, confirma ou corrige e persiste resultado oficial. Jev, LLM e demais classificadores/provedores produzem apenas propostas consultivas; falha de provedor não substitui a decisão oficial nem elimina a Revisão manual.
- A integração usa OpenRouter via HTTP e exige `OPENROUTER_MODEL`; o serviço não seleciona modelo automaticamente. Persiste model, question/schema version, Category-version, policy-version, thresholds e usage sem o request bruto.
- Exemplos recuperados podem ser usados somente como contexto de inferência após o Gate; permanecem entrada não confiável e não ajustam pesos nem acionam fine-tuning.
- O modelo configurado deve ser uma identidade versionada; alias mutável não pode ser a única referência histórica.
- Respostas são validadas por schema, allowlist e limites `0..1`; conteúdo textual ou instruções de modelo são descartados como autoridade.
- Falha, timeout, `429` ou indisponibilidade deixam a Revisão manual disponível e não substituem a decisão oficial nem alteram os Labels que ela governa.
- A política de residência, retenção, subprocessadores e termos do provedor deve ser verificada pelo Usuário antes de habilitar e-mails reais.

## Gates de habilitação

- **Bootstrap e desenvolvimento**: adaptadores reais de Gmail e OpenRouter ficam configuráveis desde o bootstrap, mas a suíte normal usa somente Gmail fake, fixtures sintéticas e provedores fake; nenhum segredo real é necessário para essa suíte.
- **Provedor externo**: exige configuração explícita, opt-in, Gate ativo, política de retenção/redaction e termos aceitos.
- **`redact`**: pode seguir automaticamente após opt-in global e redaction validada.
- **`flag`**: aguarda consentimento da Thread; negar mantém a Revisão manual.
- **`abort`**: nenhuma chamada externa é feita.
- **Sync/captura real**: somente com credenciais OAuth do Usuário e validação dos escopos `gmail.modify` e `gmail.labels`; fakes verificam a allowlist e a preservação de `INBOX` antes de qualquer smoke real.

## Testes adversariais Pest

O perfil `standard` da especificação exige provas Pest nos pontos de integração públicos e nas decisões que atravessam uma fronteira. A matriz completa está em `.specs/features/email-classification/checks.md`.

- Prompt injection em subject, corpo, quoted reply e headers não pode autorizar ação nem atravessar o Gate cru.
- Prompt injection em Exemplo recuperado não pode alterar instruções; DLP e detector local verificam a representação completa da Thread+Exemplos antes da chamada Jev.
- Saída do modelo com instruções de sistema, escolha de conta, tenant, papel, escopo ou side effect é tratada como dado sem autoridade.
- IDs de Categoria desconhecidos, arquivados ou reservados são rejeitados mesmo com confiança alta.
- `evidence_refs` sem proveniência local são omitidos.
- DLP cobre PII/segredo e testa `redact`, `flag` e `abort` separadamente.
- Logs, erros, cache e respostas HTTP são inspecionados contra corpo, token, PII, request/response bruto e embeddings.
- Replays de sync/OAuth, retries, concorrência do Cursor e jobs duplicados são exercitados com fakes controlados.
- Isolamento single-tenant é testado em cada leitura, cache e serialização; nenhum valor do modelo escolhe o escopo.
- Recuperação híbrida restringe texto/vetores à instalação e Categorias ativas com opt-in; arquivos, opt-out, retenção e wipe removem ou invalidam os índices derivados.
- Geração de embeddings usa somente o serviço local; testes provam que nenhum conteúdo de Exemplo ou embedding é enviado a um provedor externo ou exposto pela API/logs.
- Evals reais de provedor são opt-in e sintéticos; a suíte normal não chama Gmail, Jev ou outro serviço externo.

## Perguntas de produção

- Verificação do app no Google (consent screen, modo testing vs produção) e responsabilidade do Usuário.
- Residência, retenção e subprocessadores do provedor escolhido.
- Política exata de redaction padrão e seus padrões de PII/segredo.
- Seleção e fixação, pelo implementador no bootstrap, das versões exatas de PHP/Laravel, clientes Google/OAuth/OIDC e cliente HTTP do OpenRouter; essa seleção deve usar documentação oficial e não ocorre em runtime.
- Registro do cliente/audience Google OIDC e allowlist das identidades autorizadas na instalação single-tenant.

## Pendências de código e operação

- **Código**: seleção/fixação de versões e pacotes concretos, cliente HTTP e
  middleware Google OIDC; Nginx já está definido como proxy, mas a configuração
  final de audience/allowlist permanece pendente.
- **Recuperação**: resolver `PD-011` para runtime/modelo/dimensão local, fusão,
  top-k e falha limitada dos canais, preservando isolamento e opt-in.
- **Sequenciamento/tooling**: `PD-009` e `PD-010` foram permanentemente dispensadas como blockers. Isso não significa que testes/checks passaram nem que o validator exista; `validate_checks.py` continua ausente.
- **Operação**: credenciais Gmail OAuth, cliente OIDC, domínio/DNS/TLS,
  credenciais OpenRouter e `OPENROUTER_MODEL`, segredos da VPS,
  armazenamento/backups e aceitação dos termos, residência, retenção e
  subprocessadores dos provedores.
