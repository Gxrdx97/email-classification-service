# Design — email-classification-service

## Context

Serviço standalone, self-hosted, open source (Apache-2.0). Uma instalação = uma conta Gmail. Nenhuma dependência do projeto RAG. O classificador classifica qualquer e-mail em categorias criadas pelo usuário; o fluxo de cotações foi removido completamente.

## Visão geral

```text
Gmail OAuth (server-side, gmail.readonly)
  → Sync (list/get + historyId incremental)
  → Captura canônica (mensagens/threads imutáveis)
  → Enriquecimento determinístico
  → Gate de privacidade (DLP + prompt injection)
  → Classificação (regras e/ou LLM adapter)
  → categorized | uncategorized | inconclusive | failed
  → Revisão / reclassificação (humano no loop)
```

## Decisões

### D1 — Contratos (ports)

| Contrato | Responsabilidade |
|---|---|
| `MailboxSource` | listar/obter/sync mensagens; cursor por conta; provider-specific semantics |
| `OAuthConnection` | connect/callback/refresh/revoke/validação de escopos |
| `MessageRepository` | persistir mensagens, threads, versões imutáveis |
| `EnrichmentProvider` | produzir fatos determinísticos versionados com proveniência |
| `PrivacyPolicy` | decidir o que pode ir a provedor externo (redaction, bloqueio, abort) |
| `ClassifierProvider` | regras determinísticas e/ou LLM; entrada: mensagem enriquecida + categorias ativas |
| `CategoryRepository` | CRUD + versionamento de definições |
| `ClassificationRepository` | resultados, estados, revisões, histórico |

Todos os adapters de provedor (Gmail, LLM) implementam a interface neutra; fake para testes.

### D2 — Modelo de dados

**Conta e conexão**
- `account`: provider, google subject, scopes concedidos, status, `last_history_id`, last_sync.
- Token de refresh cifrado; access token curto em cache.

**Captura**
- `messages`: id canônico, provider message id (único por conta), thread_id, subject, direção (`inbound|outbound`), data, labels[], snippet, body (versão bruta), `content_hash`, headers relevantes, metadados de anexos (nome, mime, tamanho — sem conteúdo), recebido_em.
- `message_threads`: thread provider id, assunto canônico, contagem, participantes.
- Imutabilidade: correções geram nova versão, nunca mutam a bruta.

**Categorias**
- `categories`: id, name, slug (único por instalação), description, examples[] (sintéticas ou de revisões), instruction opcional, version, archived_at.
- `category_versions`: snapshot imutável por mudança; classificação referencia a versão usada.

**Classificação**
- `classifications`: message_id, state (`pending|categorized|uncategorized|inconclusive|failed`), categories[] (FKs), category_version_ids[], confidence, review_required, reason_codes[], evidence_refs[], run_id, classifier_id/classifier_version, inconclusive_reason (`low_confidence|conflict|no_match|insufficient_content`), created_at.
- `classification_runs`: batch id, modo (regras/LLM), versão de tudo, contadores.
- `reviews`: correções do operador por mensagem (vira dado de treino/ exemplo opcional).

**Enriquecimento**
- `enrichment_facts`: message_id, kind (ex. `language`, `participants_normalized`, `quoted_reply_stripped`, `signature_detected`, `thread_position`), payload JSONB, proveniência (`deterministic`), versão do extrator, valid window.
- Campos frequentemente consultados ficam relacionais; JSONB apenas para detalhes não operacionais.

**Sync**
- `sync_state`: um registro por conta — full-sync done?, `history_id`, cursor de paginação, última execução, erros tipados com backoff.

### D3 — Sincronização Gmail

- Backfill inicial: `users.messages.list` (paginado) + `users.messages.get` (`format=full`), respeitando rate limits (batch, backoff 429/5xx).
- Incremental: `users.history.list` a partir de `history_id` armazenado; labels determinam pertencimento a `INBOX`/`SENT`/arquivo.
- Se `historyId` não for mais válido → resync completo idempotente (dedupe por provider message id).
- Duplicatas: chave única `(account_id, provider_message_id)`; hash de conteúdo detecta reprocesso.
- Polling agendado (default: 15 min, configurável). Pub/Sub `watch` fica como otimização futura.

### D4 — Enriquecimento determinístico

Pipeline por mensagem (ordem fixa, cada etapa versionada):
1. Parse MIME (`message/rfc822` via parser robusto).
2. HTML → texto com preservação de estrutura mínima; fallback `text/plain`.
3. Remoção de respostas citadas (`On ... wrote:`, `>`) e assinaturas/rodapés.
4. Normalização de participantes (nome + e-mail; direção derivada de headers + labels).
5. Idioma (detecção leve; default desconhecido).
6. Correlação de thread por `threadId` + `Message-ID`/`In-Reply-To`/`References`.
7. Metadados de anexos (sem extração de conteúdo no MVP).
Cada fato grava `extractor_version`; nada probabilístico nesta camada.

### D5 — Privacidade e DLP

- Gate obrigatório antes de qualquer envio a LLM/provedor externo:
  - Padrões de PII/segredos configuráveis (regex) → `redact | flag | abort` por política.
  - Detecção de prompt injection → marca mensagem; conteúdo sinalizado nunca é enviado cru ao provedor.
- Política do operador define: provedores habilitados, redaction on/off, retenção de corpo bruto (default: 180 dias, configurável), exclusão total (wipe).
- Tokens, corpos brutos e conteúdo de e-mails reais **nunca** em logs, fixtures ou specs.

### D6 — Classificação

**Entrada do classificador**: texto enriquecido + metadados + lista de categorias ativas (id, nome, descrição, exemplos, instruction).

**Saída estruturada** (validada contra allowlist de IDs):
```text
categories[]                 // só IDs existentes; multi-categoria permitida
confidence: 0..1
review_required: bool
reason_codes[]               // ex.: matched_examples, keyword_rule, model_low_confidence, injection_flagged
evidence_refs[]              // refs a exemplos/fatos usados
```

**Adapters**:
- `RuleBasedClassifier` (determinístico, zero custo, primeiro a rodar).
- `LlmClassifierProvider` — prompt com categorias ativas; structured output validado; IDs desconhecidos → rejeitados; sem categoria aplicável → `uncategorized`; sem confiança/conflito → `inconclusive` + reason.
- Modelo/configur por operador (env/config); nenhum modelo fixo no núcleo.

**Estados**:
```text
pending        → ainda não processado
categorized    → ≥1 categoria atribuída
uncategorized  → processado; nenhuma categoria aplicável
inconclusive   → abstenção (reason obrigatório)
failed         → erro técnico; retry com backoff
```

### D7 — Jobs e execução

- Fila persistida no banco (padrão) ou Redis opcional; worker single-process self-hosted.
- Jobs: `SyncAccountJob`, `BackfillJob`, `EnrichMessageJob`, `ClassifyMessageJob`, `ReclassifyBatchJob`, `RetentionSweepJob`.
- Idempotência: sync por cursor; classificação por `(message_id, categories_version, classifier_id)` — reclassifica só quando mudou definição/modelo.
- Scheduler interno (cron/loop) dispara sync periódico.

### D8 — API/CLI

- **CLI** (fluxo primário self-hosted):
  - `ecs:connect` (inicia OAuth, imprime URL, captura callback local)
  - `ecs:sync` (full/incremental)
  - `ecs:enrich`, `ecs:classify [--all|--since=...]`
  - `ecs:categories:list|create|update|archive`
  - `ecs:review:list|set` (correção humana)
  - `ecs:reclassify`
- **HTTP opcional** (interface local autenticada por token): listar mensagens/estados, CRUD categorias, disparar sync/classify, revisão. Nunca expõe corpos brutos por padrão (redaction na saída).

### D9 — Segurança

- OAuth state server-side, one-time, associado à instalação; redirect fixo.
- Escopo solicitado: apenas `gmail.readonly`; validação na resposta do Google.
- Refresh token cifrado (chave via env `APP_KEY`-equivalente), nunca logado.
- Segredos só via env/secret store; `.env` fora do VCS.
- Todas as escritas de dados de e-mail passam pelos repositórios; sem acesso direto ao DB em camadas superiores.

### D10 — Configuração (`config.yaml` + env)

- `gmail`: client_id/client_secret (env), scopes fixos, redirect_uri, polling interval.
- `sync`: backfill window (default: todo histórico), page size.
- `privacy`: provedores externos habilitados (default: nenhum), redaction rules, retention_days, wipe.
- `classifier`: adapter ativo (rules|llm), provider/model/threshold, inconclusive_threshold.
- `queue`: driver (database|redis), worker.
- `storage`: database URL (Postgres default; SQLite suportado para single-user leve).

### D11 — Testes

- Unit: parser MIME, quoted-reply/signature stripping, participantes, idioma, cursor/histórico, política de privacidade, validação de saída do classificador.
- Feature: fluxo completo com fake source + categorias sintéticas; estados `uncategorized`/`inconclusive`; reclassificação versionada; OAuth flow com fake endpoints; resync após expiração de history.
- Evals opt-in (`--evals`): fixtures sintéticas .eml + holdout; scorer valida IDs contra categorias do fixture; proíbe chaves sensíveis na saída.
