# Proposal — email-classification-service

## Why

O usuário precisa classificar automaticamente os e-mails da própria conta Gmail pessoal (mais de 4.000 mensagens) em categorias **definidas por ele mesmo**, sem depender de regras fixas embutidas no código. O projeto RAG original (`email-quote-classification`) era quote-cêntrico e acoplado a pgvector, AGE, tenancy multi-tenant e dispatch comercial — nenhuma dessa lógica é reaproveitável e o objetivo agora é um produto independente e open source.

## What Changes

- **Serviço standalone e self-hosted**, em repositório próprio, sem qualquer dependência do RAG/lucida.
- **Integração Gmail somente leitura**: Gmail API + OAuth 2.0 server-side, escopo `gmail.readonly`, uma conta Gmail por instalação.
- **Sincronização**: backfill inicial via `users.messages.list/get` e incremental via `historyId`/`history.list` (um cursor por conta; `INBOX`/`SENT` tratados como labels lógicos do Gmail, não caixas físicas).
- **Captura canônica**: mensagens e threads imutáveis com metadados do provedor, headers, corpo e hash de conteúdo.
- **Enriquecimento determinístico**: normalização MIME/HTML, remoção de respostas citadas e assinaturas, participantes, direção (inbound/outbound), idioma, correlação de thread e metadados de anexos (sem OCR no MVP).
- **Política de privacidade/DLP**: gate obrigatório antes de qualquer processamento por LLM ou provedor externo.
- **Categorias definidas pelo usuário**: CRUD versionado (`name`, `slug`, `description`, `examples`, `instruction` opcional). O classificador só pode atribuir categorias existentes; nunca inventa categorias.
- **Classificação multi-categoria** (uma mensagem pode receber várias categorias) com resultado estruturado: `categories[]`, `confidence`, `review_required`, `reason_codes[]`, `evidence_refs[]`.
- **Estados operacionais reservados** (fora do namespace de categorias): `pending | categorized | uncategorized | inconclusive | failed`, com `inconclusive_reason` tipado (`low_confidence | conflict | no_match | insufficient_content`).
- **Provedores externos de IA permitidos** via adapters configuráveis (opt-in por operador); processamento local/sem LLM permanece suportado.
- **Classificação executável**: CLI e/ou endpoint HTTP local para disparar sync e classificação; fila interna para jobs.
- **Revisão e reclassificação**: operador pode corrigir categorias; reclassificação em lote usa a versão atual das definições de categoria.
- **Fixtures sintéticas e evals opt-in**, sem e-mails reais nem dados pessoais no repositório.

## Delivery Boundaries

1. **Conexão e sincronização Gmail** — OAuth, tokens, cursor `historyId`, resync completo quando o histórico expira.
2. **Captura e enriquecimento** — armazenamento canônico + pipeline determinístico.
3. **Privacidade** — DLP, redaction configurável, política de envio a provedores.
4. **Classificação** — categorias versionadas, adapters de classificador (regras + LLM), estados reservados.
5. **Operação** — CLI/HTTP, fila, observabilidade, retenção e exclusão de dados.

## Out of Scope

- Fluxo de cotações/quotes: **removido completamente** (não é adiado — não existe nesta versão).
- Envio, resposta, mudança de labels ou qualquer escrita no Gmail.
- Multi-conta, multi-tenant, SaaS.
- RAG, pgvector, AGE, resolved cases, `QuoteRequest`, dispatch, fingerprint comercial, aprovação comercial.
- OCR e extração de conteúdo de anexos (apenas metadados).
- Gmail Pub/Sub `watch` (polling agendado primeiro; `watch` como otimização futura).
- IMAP (portabilidade para outros provedores fica como adapter futuro).

## Impact

- **Novo produto** open source (licença Apache-2.0) em `~/email-classification-service`.
- **RAG/lucida**: a change `email-quote-classification` fica **superseded** — nenhum código será implementado sobre ela.
- Capacidade única: `email-classification`.
