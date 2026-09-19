# Tasks — email-classification-service

Status inicial: **0/N** — nenhum código implementado. Ordem global: contratos → OAuth/sync → captura/enriquecimento → privacidade → categorias/classificação → operação/evals.

## 1. Bootstrap

- [ ] T-01 Inicializar projeto (stack a definir — candidata PHP/Laravel), esqueleto, config.yaml + env, CI mínima
- [ ] T-02 Persistência (Postgres default; SQLite opcional) + migrations do modelo (accounts, messages, threads, enrichment_facts, categories, category_versions, classifications, runs, reviews, sync_state)
- [ ] T-03 Fila/worker (database driver default; Redis opcional) + scheduler de sync

## 2. Contratos e fakes

- [ ] T-04 `MailboxSource`, `OAuthConnection`, `MessageRepository`, `EnrichmentProvider`, `PrivacyPolicy`, `ClassifierProvider`, `CategoryRepository`, `ClassificationRepository` (interfaces + fakes)
- [ ] T-05 Fixtures sintéticas `.eml` (sem dados reais): inbox, sent, quoted replies, assinaturas, anexos, HTML, injection, PII

## 3. OAuth Gmail

- [ ] T-06 Fluxo `ecs:connect` (state server-side one-time, callback local, validação de escopo `gmail.readonly`)
- [ ] T-07 Refresh/revogação; storage cifrado de refresh token; erros tipados
- [ ] T-08 Testes de OAuth com endpoints fake (sucesso, escopo insuficiente, revogação)

## 4. Sincronização

- [ ] T-09 Backfill inicial `messages.list/get` com rate limit/backoff e dedupe `(account, provider_message_id)`
- [ ] T-10 Incremental `history.list` + cursor por conta; avanço só após persistência
- [ ] T-11 Resync completo quando `history_id` expira (idempotente)
- [ ] T-12 Labels → direção/filhos (INBOX/SENT/labels lógicos), thread correlation por `threadId`+headers

## 5. Captura e enriquecimento

- [ ] T-13 Parse MIME robusto; HTML→texto; body bruto imutável + `content_hash`
- [ ] T-14 Remoção de quoted replies e assinaturas (versionada, com testes de borda)
- [ ] T-15 Participantes normalizados, direção, idioma, posição na thread, metadados de anexos
- [ ] T-16 `enrichment_facts` com proveniência/versão por fato

## 6. Privacidade

- [ ] T-17 DLP configurável (regex PII/segredos) + detector de prompt injection
- [ ] T-18 Política `redact|flag|abort` por operador; provedor externo desabilitado por default
- [ ] T-19 Retenção (retention_days) + sweep job + comando de wipe

## 7. Categorias

- [ ] T-20 CRUD de categorias (slug único; slugs reservados rejeitados)
- [ ] T-21 Versionamento imutável (category_versions) e referência por classificação
- [ ] T-22 Arquivamento (categoria arquivada sai da elegibilidade sem perder histórico)

## 8. Classificação

- [ ] T-23 `RuleBasedClassifier` (keywords/exemplos, determinístico)
- [ ] T-24 `LlmClassifierProvider` (prompt com categorias ativas, structured output, timeout, erros tipados)
- [ ] T-25 Validação de saída (IDs against allowlist, confidence, review_required)
- [ ] T-26 Estados + `inconclusive_reason` tipado; retry/backoff para `failed`
- [ ] T-27 Idempotência de classificação por `(message, categories_version, classifier)`
- [ ] T-28 Reclassificação em lote após mudança de definição (preservando histórico)

## 9. Revisão

- [ ] T-29 Correção humana por mensagem (proveniência humana; estado `categorized`)
- [ ] T-30 Exemplos: permitir usar revisões como exemplos de categoria (opt-in)

## 10. Operação

- [ ] T-31 CLI completo (`ecs:sync|enrich|classify|categories|review|reclassify`)
- [ ] T-32 HTTP local opcional autenticado (listar/estados/CRUD/disparar) com redaction na saída
- [ ] T-33 Observabilidade: métricas de sync/classificação, logs sanitizados (sem corpo/token/PII)
- [ ] T-34 Docs: instalação, OAuth consent screen (testing/produção), política de privacidade do operador, licença

## 11. Qualidade

- [ ] T-35 Suite completa (unit/feature) cobrindo todos os cenários da spec
- [ ] T-36 Evals opt-in com holdout sintético e scorer validando IDs contra categorias do fixture
