# Tarefas — email-classification-service

Status do projeto: bootstrap Laravel/Composer e cinco migrations baseline
PostgreSQL existentes; a feature de domínio permanece incompleta. O único teste
executado e aprovado até agora é o teste de schema/migrations
`tests/Integration/Persistence/DomainSchemaMigrationTest.php` (5 testes, 41
assertions, zero warnings/falhas). O quality gate completo não foi declarado
concluído.

Este arquivo é apenas um índice operacional legado para implementação futura. As obrigações
canônicas, provas e cobertura estão em `.specs/features/email-classification/plan.md` e
`.specs/features/email-classification/checks.md`; não criar outro task breakdown dentro de
`.specs`.

## 0. Gates preparatórios dispensados

- [x] T-00 **Dispensada (waived) permanentemente por decisão do Usuário**;
  `PD-009` dispensado como blocker. A atividade preparatória não é declarada
  como executada.
- [x] T-00a **Dispensada (waived) permanentemente por decisão do Usuário**;
  `PD-010` dispensado como blocker. `validate_checks.py` não é declarado criado
  ou corrigido.

As marcações registram somente a dispensa dos gates; não afirmam que a atividade
de T-00 ocorreu nem que o validator foi implementado.

## 1. Inicialização

- [ ] T-01 Inicializar o ambiente Laravel/PHP e o esqueleto do serviço; selecionar e fixar versões compatíveis após validar a documentação oficial
- [ ] T-02 Inicializar Pest e a configuração do perfil `standard` usando a matriz Laravel/PHP fixada pelo implementador
- [ ] T-03 Persistência PostgreSQL para accounts, messages, threads, sync_state, enrichment_facts, categories, category_versions, pre_analyses, category_suggestions, classifications, runs e reviews
- [ ] T-04 Fila/worker Redis + agendador de sincronização em Docker

## 2. Contratos e fakes

- [ ] T-05 `MailboxSource`, `OAuthConnection`, `MessageRepository`, `EnrichmentProvider`, `PrivacyPolicy`, `ClassifierProvider`, `PreAnalysisProvider`, `CategoryRepository`, `ClassificationRepository` e `PreAnalysisRepository`
- [ ] T-06 Fakes de Gmail, OAuth, Gate, classificador, pré-analisador e transport HTTP
- [ ] T-07 Fixtures sintéticas `.eml` para inbox, sent, quoted replies, assinaturas, HTML, anexos, PII, secrets e prompt injection

## 3. OAuth Gmail

- [ ] T-08 Fluxo `ecs:connect` com state no servidor, de uso único, callback fixo no servidor, validação de `gmail.modify` + `gmail.labels` (sem `mail.google.com`) e guia operacional do aplicativo OAuth Google; sem envio, arquivamento ou exclusão de mensagens
- [ ] T-09 Refresh/revogação, storage cifrado do refresh token e erros tipados
- [ ] T-10 Testes Pest de OAuth: sucesso, escopo insuficiente, replay, redirect inválido e revogação

## 4. Sincronização

- [ ] T-11 Carga inicial `messages.list/get` com limites de taxa, backoff e dedupe por account + provider message id
- [ ] T-12 Incremental `history.list` + Cursor por conta; avanço somente após persistência
- [ ] T-13 Resync completo idempotente quando `history_id` expira
- [ ] T-14 Labels, direção, Participantes e correlação por `threadId` + headers
- [ ] T-15 Enfileiramento assíncrono após confirmação de captura e Cursor
- [ ] T-16 Testes Pest de retries, duplicatas, concorrência e falha parcial do Cursor

## 5. Captura e enriquecimento

- [ ] T-17 Análise MIME; HTML para texto; corpo bruto imutável e `content_hash`
- [ ] T-18 Remoção de respostas citadas e assinaturas, com fatos versionados
- [ ] T-19 Participantes normalizados, direção, idioma, posição na Thread e metadados de anexos
- [ ] T-20 `enrichment_facts` com provenance e versão por fato
- [ ] T-21 Testes Pest garantindo que conteúdo de anexo nunca é lido, persistido ou enviado

## 6. Privacidade

- [ ] T-22 DLP configurável para PII/secrets + detector local de prompt injection
- [ ] T-23 Política `redact|flag|abort`; adaptadores reais configuráveis desde o bootstrap, com chamadas externas desabilitadas por padrão na suíte normal
- [ ] T-24 Consentimento por Thread para `flag`
- [ ] T-25 Retenção (`retention_days`), sweep e wipe total
- [ ] T-26 Testes Pest adversariais de gate, logs, erros, cache, redaction e isolamento single-tenant

## 7. Categorias

- [ ] T-27 CRUD de Categorias (slug único; namespace operacional reservado)
- [ ] T-28 Versionamento imutável de `category_versions`
- [ ] T-29 Arquivamento sem apagar histórico; Categoria arquivada sai da allowlist ativa
- [ ] T-30 Testes Pest de versões, sobreposição, reserved slugs e allowlist

## 8. Classificação oficial por Thread

- [ ] T-31 `RuleBasedClassifier` determinístico sobre a Thread inteira
- [ ] T-32 `LlmClassifierProvider` com saída estruturada, timeout e erros tipados
- [ ] T-33 Validação da saída: IDs, confidence, proveniência de evidence e `review_required`
- [ ] T-34 Estados oficiais e `inconclusive_reason` tipado; nova tentativa/backoff para `failed`
- [ ] T-35 Herança Thread → Message e divergência de correção restrita à mensagem
- [ ] T-36 Testes Pest cobrindo `pending|categorized|uncategorized|inconclusive|failed`

## 9. Pré-análise Jev

- [ ] T-37 Integração HTTP `PreAnalysisProvider` via OpenRouter com `OPENROUTER_MODEL` obrigatório, sem seleção automática, e model/question/schema version explícitos
- [ ] T-38 `PreAnalyzeThreadJob` assíncrono somente para Threads novas ou pendentes sem resultado oficial anterior e com Jev habilitado; `provider=none` não cria job
- [ ] T-39 Idempotência por `account_id` + `thread_id` + `thread_snapshot_hash` + `category_catalog_version` + `provider_model` + `privacy_policy_version`
- [ ] T-40 Persistência separada de `pre_analyses` e `category_suggestions`
- [ ] T-41 Validação de probabilities `0..1`, allowlist, versão da Categoria e formato da resposta
- [ ] T-42 Falha/timeout/429 sem mutação de Estado operacional e com alternativa de Revisão manual
- [ ] T-43 Testes Pest adversariais da saída do modelo: injection, tentativa de controlar Categoria/conta/escopo e evidence refs
- [ ] T-44 Evals sintéticos com opt-in em português e com Threads longas/truncadas; nenhum provedor real na suíte normal

## 10. Revisão e reclassificação

- [ ] T-45 `ecs:review:list` exibe sugestões sem aplicação automática
- [ ] T-46 `ecs:review:set` confirma, rejeita ou corrige no escopo Thread/Message com proveniência humana; a Revisão precede o primeiro resultado oficial
- [ ] T-47 Correção pode virar Exemplo somente com opt-in da Categoria e cria nova versão
- [ ] T-48 `ecs:reclassify` usa versões atuais, preserva histórico e não sobrescreve correção humana
- [ ] T-49 Testes Pest de confirmação, rejeição, divergência e resultado adesivo

## 11. Busca híbrida de Exemplos

- [ ] T-55 Persistir Exemplo somente após Revisão humana elegível e opt-in da Categoria, com vínculo imutável à Revisão e à versão da Categoria
- [ ] T-56 Gerar embeddings localmente e manter índice semântico `pgvector` e representação lexical PostgreSQL sem transmissão externa
- [ ] T-57 Recuperar pelos canais lexical e semântico, limitando resultados à instalação e Categorias ativas/autorizadas; registrar IDs/versões recuperados
- [ ] T-58 Aplicar o Gate ao payload completo Thread+Exemplos e tratar cada Exemplo como conteúdo não confiável
- [ ] T-59 Excluir Exemplos inelegíveis e remover/invalidar texto e índices em opt-out, arquivamento, retenção e wipe
- [ ] T-60 Avaliar a recuperação com fixtures sintéticas, proveniência verificável e sem provedores reais na suíte normal
- [ ] T-61 Manter fine-tuning e atualização de pesos fora da entrega inicial; correções somente alimentam Exemplos opt-in

## 12. Operação e interfaces

- [ ] T-50 CLI completo (`ecs:sync|enrich|classify|categories|review|reclassify|retention|wipe`)
- [ ] T-51 API HTTP obrigatória na VPS para sync, reviews, consentimento e categorias, publicada por Nginx com HTTPS/TLS, Bearer Google OIDC e redaction na saída
- [ ] T-52 Observabilidade sanitizada: métricas, reason codes e logs sem corpo/token/PII/payload de provedor
- [ ] T-53 Documentação de instalação, guia da tela de consentimento OAuth Gmail, configuração Google OIDC, proxy HTTPS, segredos, armazenamento/backups da VPS, termos do provedor e política de privacidade
- [ ] T-54 Plugins Pest de architecture/type/coverage/mutation/snapshot/stress/browser somente nos seams aplicáveis
- [ ] T-62 Atualizar consentimento OAuth para a projeção restrita de Labels de Categoria (`gmail.modify` + `gmail.labels`), rejeitando `mail.google.com`
- [ ] T-63 Criar/reutilizar Labels customizadas de nome exato e persistir mapeamento estável Categoria↔`labelId`
- [ ] T-64 Calcular associações desejadas por Message, incluindo herança da Thread e precedência de correção oficial restrita à Message
- [ ] T-65 Persistir intenção durável pós-commit e estado de reconciliação sem misturar Labels capturadas com Labels geridas
- [ ] T-66 Implementar reconciliação idempotente com retry, preservação de `INBOX`/Labels alheios e rejeição de IDs/ações não autorizados
- [ ] T-67 Adicionar testes fakes e comando `ecs:labels:reconcile [--thread=<id>|--all]`; tarefas detalhadas permanecem em Spec Lean
