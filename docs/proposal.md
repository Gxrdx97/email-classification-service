# Proposta — email-classification-service

## Por quê

O usuário precisa processar automaticamente os e-mails da própria conta Gmail pessoal (mais de 4.000 mensagens) e receber propostas de categorias **definidas por ele mesmo**, sem depender de regras fixas embutidas no código. Somente a Revisão humana escolhe, confirma ou corrige e persiste o resultado oficial. O projeto RAG original (`email-quote-classification`) era centrado em cotações e acoplado a pgvector, AGE, tenancy multi-tenant e dispatch comercial — nenhuma dessa lógica é reaproveitável e o objetivo agora é um produto independente e de código aberto.

## O que muda

- **Serviço autônomo e auto-hospedado**, em repositório próprio, sem qualquer dependência do RAG/lucida.
- **Integração Gmail**: Gmail API + OAuth 2.0 no servidor, uma conta Gmail por instalação; captura de mensagens e sincronização limitada de Labels de Categoria após decisão oficial revisada.
- **Sincronização**: backfill inicial via `users.messages.list/get` e incremental via `historyId`/`history.list` (um cursor por conta; `INBOX`/`SENT` tratados como labels lógicos do Gmail, não caixas físicas).
- **Captura canônica**: mensagens e threads imutáveis com metadados do provedor, headers, corpo e hash de conteúdo.
- **Enriquecimento determinístico**: normalização MIME/HTML, remoção de respostas citadas e assinaturas, participantes, direção (inbound/outbound), idioma, correlação de thread e metadados de anexos (sem OCR no MVP).
- **Política de privacidade/DLP**: Gate obrigatório antes de qualquer processamento por LLM, Jev ou provedor externo.
- **Categorias definidas pelo usuário**: CRUD versionado (`name`, `slug`, `description`, `examples`, `instruction` opcional). Classificadores só podem propor categorias existentes; nunca inventam categorias nem persistem resultado oficial.
- **Propostas de classificação por Thread** multi-categoria, com Herança para mensagens e resultado estruturado: `categories[]`, `confidence`, `review_required`, `reason_codes[]`, `evidence_refs[]`. Somente a Revisão humana escolhe, confirma ou corrige e persiste o resultado oficial.
- **Estados operacionais reservados** (fora do namespace de categorias): `pending | categorized | uncategorized | inconclusive | failed`, com `inconclusive_reason` tipado (`low_confidence | conflict | no_match | insufficient_content`).
- **Provedores externos de IA permitidos** via provedores configuráveis (opt-in pelo Usuário); a integração real do Jev usa OpenRouter e exige `OPENROUTER_MODEL`, sem seleção automática de modelo. Processamento local/sem LLM permanece suportado.
- **Pré-análise Jev**: após sync, quando o provider estiver habilitado, tarefa assíncrona para Threads novas ou pendentes sem resultado oficial anterior produz Sugestões de categoria; `provider=none` não cria job e nenhuma sugestão altera o Estado operacional. Somente a Revisão humana escolhe, confirma ou corrige e persiste o resultado oficial. O Gate de privacidade usa `abort` por padrão e registra somente evento sanitizado quando bloqueia uma Thread.
- **Classificação executável**: CLI `ecs:*` e API HTTP autenticada na VPS para disparar sync e gerar propostas de classificação; a API fica atrás de Nginx com HTTPS/TLS e usa Google OIDC Bearer, separado do OAuth Gmail. Redis sustenta a fila e o agendador. Falha de provedor não substitui a decisão oficial nem elimina a Revisão manual.
- **Revisão e reclassificação**: o Usuário pode confirmar/corrigir categorias; reclassificação em lote usa a versão atual das definições sem sobrescrever correções humanas.
- **Recuperação de Exemplos**: correções humanas viram Exemplos versionados somente com opt-in por Categoria; PostgreSQL full-text e `pgvector` os recuperam como contexto de inferência, com embeddings locais e sem fine-tuning.
- **Fixtures sintéticas e evals opt-in**, sem e-mails reais nem dados pessoais no repositório; testes Pest incluem casos adversariais de conteúdo não confiável.

## Limites da entrega

1. **Conexão e sincronização Gmail** — OAuth, tokens, cursor `historyId`, resync completo quando o histórico expira.
2. **Captura e enriquecimento** — armazenamento canônico + pipeline determinístico.
3. **Privacidade** — DLP, redaction configurável, política de envio a provedores.
4. **Pré-análise e classificação** — propostas consultivas de Jev/classificadores (regras + LLM), categorias versionadas, estados reservados e Revisão humana, única autoridade para persistir resultado oficial.
5. **Labels de Categoria no Gmail** — criar/reutilizar um Label personalizado por Categoria e projetar atribuições oficiais às mensagens correspondentes, preservando labels alheios e `INBOX`.
6. **Operação** — CLI/HTTP, fila, observabilidade, retenção e exclusão de dados.

## Fora do escopo

- Fluxo de cotações/quotes: **removido completamente** (não é adiado — não existe nesta versão).
- Envio/resposta de e-mails, arquivamento e exclusão de mensagens. A única escrita permitida no Gmail é adicionar/remover Labels de Categoria geridos pelo serviço após resultado oficial revisado.
- Multi-conta, multi-tenant, SaaS.
- RAG genérico, AGE, agents, memória, resolved cases, `QuoteRequest`, dispatch, fingerprint comercial e aprovação comercial. A única recuperação permitida é de Exemplos de Revisões humanas opt-in, com Gate aplicado ao payload completo; seus embeddings são derivados sensíveis e não acionam fine-tuning.
- OCR e extração de conteúdo de anexos (apenas metadados).
- Gmail Pub/Sub `watch` (polling agendado primeiro; `watch` como otimização futura).
- IMAP (portabilidade para outros provedores fica como integração futura).

## PD-009 e PD-010 — blockers dispensados

`PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
bootstrap. Isso não significa que testes ou checks passaram, não afirma que a
atividade preparatória de `PD-009` foi concluída e não cria o
`validate_checks.py` referido pelos checks, que continua ausente. A dispensa não
altera o escopo funcional aprovado.

## Impacto

- **Novo produto** de código aberto (licença Apache-2.0) em `~/email-classification-service`; Laravel + Pest, PostgreSQL, Redis e Docker formam o baseline. O implementador fixa as versões e os clientes concretos durante o bootstrap, após validar a documentação oficial. Nginx é o proxy reverso definido; o registro OIDC permanece pendente.
- **RAG/lucida**: a change `email-quote-classification` fica **substituída** — nenhum código será implementado sobre ela.
- Capacidade única: `email-classification`.

## Pendências de operação

Credenciais Gmail OAuth, cliente Google OIDC, domínio/DNS/TLS, credenciais
OpenRouter e `OPENROUTER_MODEL`, segredos da VPS, armazenamento/backups e a
análise de termos, residência, retenção e subprocessadores dos provedores são
pré-requisitos operacionais. Eles não substituem as lacunas de código listadas
nas tarefas.
