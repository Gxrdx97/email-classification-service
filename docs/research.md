# Research — email-classification-service

## Contexto

Evolução da change `email-quote-classification` (repo `rag`). O escopo mudou três vezes até estabilizar:

1. Classificação quote-cêntrica sobre o RAG (original).
2. Classificação genérica de qualquer e-mail (reframe).
3. **Serviço standalone open source, self-hosted, com categorias definidas pelo usuário** (estado final).

Toda a lógica do RAG (ingestão, pgvector, AGE, tenancy DB-per-tenant, Horizon, agents de busca) **não é reaproveitável** — o serviço nasce do zero.

## Descobertas sobre o ambiente de origem

- Não existia implementação de e-mail no repo `rag`: nenhum adapter Gmail/IMAP, nenhuma migração, nenhuma tabela de mensagens.
- `config/mail.php` é apenas mailer de saída Laravel; `config/services.php` não tem Google.
- `composer.json` não tem Google API client nem cliente IMAP.
- Dezancy existentes (middleware de tenant fail-open, fallback `tenant_id = domain_id`) eram inseguras para e-mail privado e reforçam a decisão de não reaproveitar o código.
- O harness de evals (`EmailClassificationOutputScorer`) era útil como conceito (structured output, códigos de razão, proibição de chaves sensíveis) mas a taxonomia `positive|negative|review` é descartada.

## Gmail: API vs IMAP

**Decisão: Gmail REST API com OAuth 2.0 server-side, somente leitura.**

| Aspecto | Gmail API | IMAP XOAUTH2 |
|---|---|---|
| Escopo OAuth | `gmail.readonly` (restrito, mas mínimo p/ corpo) | `https://mail.google.com/` (amplíssimo) |
| Threading | `threadId` nativo | Reconstrução por headers |
| Incremental | `historyId` + `history.list` | UID/UIDVALIDITY por pasta |
| Labels | Nativos | Mapeados como pastas/X-GM-LABELS |
| Verificação Google | Requerida p/ escopo restrito | Igual |

- `gmail.metadata` é insuficiente (não expõe corpo). `gmail.readonly` é o mínimo que retorna corpo.
- Senha de app / senha simples **não** é opção aceitável.
- `watch` (Pub/Sub) existe mas exige renovação ≤7 dias; polling agendado primeiro.

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
- Duas situações sem categoria são estados operacionais distintos:
  - `uncategorized`: processado, nenhuma categoria aplicável.
  - `inconclusive`: classificação rodou mas o sistema se absteve (baixa confiança, conflito, sem match, conteúdo insuficiente).
- O classificador recebe as categorias ativas e só pode retornar IDs existentes.
- Definições versionadas: editar descrição/exemplos não reescreve o histórico de classificações anteriores.

## Provedores de classificação

- Requisito do usuário: **poder enviar a provedores externos** — adapters configuráveis (ex.: OpenAI, Anthropic, OpenRouter, Ollama).
- Opt-in por operador; o padrão pode ser processamento local/regras.
- Conteúdo de e-mail é não confiável: DLP + detecção de prompt injection **antes** de qualquer chamada externa.
- E-mails reais nunca entram no repositório: fixtures sintéticas `.eml` e holdouts gerados.

## Segurança / privacidade

- Tokens OAuth: refresh token cifrado em repouso, nunca logado.
- Escopo mínimo: `gmail.readonly` (sem `gmail.modify`, sem `mail.google.com`).
- Consentimento interativo obrigatório (conta pessoal; domain-wide delegation não se aplica).
- Verificação do app Google (escopo restrito) é pré-requisito de publicação, não de desenvolvimento local (modo testing).
- Retenção configurável de corpos brutos; exclusão real de dados a pedido do operador.

## Stack

- Decisão em aberto (pergunta não respondida pelo usuário). Candidata natural: **PHP + Laravel** (familiaridade do autor, symfony/mime para MIME, HTTP client embutido). Alternativas: TypeScript/Node, Go, Python.
- Fila: persistente no banco (evita Redis como dependência obrigatória) ou Redis opcional.

## Fases recomendadas

1. Contratos + fake source + fixtures `.eml` sintéticas.
2. OAuth Gmail (conect, callback, revoke, refresh) + sync completo/incremental.
3. Captura canônica + enriquecimento determinístico.
4. DLP/política de privacidade + adapters de classificador.
5. Categorias versionadas + estados reservados + revisão/reclassificação.
6. CLI/HTTP + observabilidade + retenção.
7. Evals opt-in com fixtures sintéticas.
