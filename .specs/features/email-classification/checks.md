# email-classification checks

Profile: standard
Plan: `.specs/features/email-classification/plan.md`

62 critérios planejados em 7 fatias · 7 portas irreversíveis registradas · PD-009/PD-010 dispensadas permanentemente como blockers · PD-012 resolvida

## Precondições de execução

- `PD-009` e `PD-010` estão permanentemente dispensadas como blockers; `PD-012`
  está resolvida. Essas decisões não provam checks ou testes, nem permitem
  declarar um resultado `green`.
- A implementação deve fixar versões e clientes compatíveis durante o
  bootstrap, após validar a documentação oficial. Nginx é o proxy confirmado.
- Adaptadores reais de Gmail e OpenRouter podem ser configurados desde o
  bootstrap, mas os selectors e a suíte Pest normal usam fakes e fixtures
  sintéticas. Smoke/evals reais exigem opt-in e segredos fora do repositório.

## Estado de execução

- C1–C62 são critérios planejados e ainda não foram executados. Um check só pode
  ser chamado `green` após sua execução real e o registro da evidência; waivers
  de blockers não contam como evidência.
- Única evidência real registrada: `tests/Integration/Persistence/DomainSchemaMigrationTest.php --fail-on-warning --display-warnings --no-progress` — 5 tests, 41 assertions, 0 failures e 0 warnings no banco local de teste. Essa execução não prova C1–C62.

## Checks

### S1 - captura canônica e sincronização · 6 verificações · 4 arquivos de testes futuros · ~10k

**C1** - O primeiro sync persiste versões de mensagens e threads canônicas antes de o Cursor da conta avançar (plano AC 1)
Proof: `./vendor/bin/pest --filter='first_sync_persists_before_cursor'`

**C2** - O sync incremental persiste registros alterados antes de armazenar o próximo valor do Cursor (plano AC 2)
Proof: `./vendor/bin/pest --filter='incremental_sync_advances_cursor_after_persistence'`

**C3** - Uma alteração do Gmail somente em Labels atualiza Labels sem criar uma versão de mensagem (plano AC 3)
Proof: `./vendor/bin/pest --filter='labels_only_change_does_not_create_content_version'`

**C4** - Histórico expirado dispara um resync completo idempotente sem duplicar a identidade de mensagem do provedor (plano AC 4)
Proof: `./vendor/bin/pest --filter='expired_history_runs_idempotent_full_resync'`

**C5** - Um `content_hash` alterado preserva a versão anterior da mensagem e armazena uma nova versão imutável (plano AC 5)
Proof: `./vendor/bin/pest --filter='content_change_preserves_prior_message_version'`

**C6** - Uma Message nova em uma Thread com resultado oficial pode deixar o Estado operacional da Thread como `pending`, preservando o resultado oficial anterior e os Labels herdados até ação explícita de Revisão humana, sem enfileirar reclassificação automática (plano AC 6)
Proof: `./vendor/bin/pest --filter='new_message_does_not_auto_reclassify_classified_thread'`

### S2 - enriquecimento e fronteira de privacidade · 7 verificações · 5 arquivos de testes futuros · ~12k

**C7** - O enriquecimento armazena texto normalizado, Participantes, direção, idioma, posição na thread e proveniência do extrator (plano AC 7)
Proof: `./vendor/bin/pest --filter='enrichment_persists_required_deterministic_facts'`

**C8** - O processamento de Anexo armazena nome, tipo de mídia e tamanho sem ler ou transmitir seu conteúdo (plano AC 8)
Proof: `./vendor/bin/pest --filter='attachment_content_never_crosses_metadata_boundary'`

**C9** - Uma chamada a provedor externo exige opt-in explícito e um Gate de privacidade bem-sucedido (plano AC 9)
Proof: `./vendor/bin/pest --filter='external_provider_requires_opt_in_and_gate'`

**C10** - Uma decisão `abort` do Gate produz zero chamadas a provedor externo para a Thread (plano AC 10)
Proof: `./vendor/bin/pest --filter='abort_gate_makes_no_external_call'`

**C11** - Uma decisão `flag` do Gate permanece sem envio até existir consentimento explícito para essa Thread (plano AC 11)
Proof: `./vendor/bin/pest --filter='flag_gate_waits_for_thread_consent'`

**C12** - Uma decisão `redact` do Gate envia somente a representação enriquecida redigida (plano AC 12)
Proof: `./vendor/bin/pest --filter='redact_gate_sends_only_redacted_representation'`

**C13** - Logs e respostas de erro não contêm corpos brutos, tokens OAuth, PII nem respostas brutas de provedores (plano AC 13)
Proof: `./vendor/bin/pest --filter='sanitized_logging_excludes_sensitive_values'`

### S3 - categorias e classificação oficial · 9 verificações · 6 arquivos de testes futuros · ~15k

**C14** - Edições de Categoria criam versões imutáveis enquanto as versões anteriores permanecem endereçáveis (plano AC 14)
Proof: `./vendor/bin/pest --filter='category_edit_preserves_immutable_versions'`

**C15** - Cada slug operacional reservado é rejeitado como slug de Categoria (plano AC 15)
Proof: `./vendor/bin/pest --filter='reserved_operational_slugs_are_rejected'`

**C16** - Somente a Revisão humana escolhe, confirma ou corrige a Categoria e persiste o resultado oficial, após receber a Thread enriquecida completa e as versões ativas de Categoria; Jev/LLM e provedores produzem apenas propostas consultivas (plano AC 16)
Proof: `./vendor/bin/pest --filter='human_review_is_only_official_result_writer'`

**C17** - Uma Revisão humana que escolhe ou confirma o ID de uma Categoria ativa persiste `categorized` na Thread e resultados herdados em cada Message (plano AC 17)
Proof: `./vendor/bin/pest --filter='categorized_thread_is_inherited_by_messages'`

**C18** - Quando a Revisão humana confirma uma proposta explícita de nenhuma Categoria com confiança `0.60`, persiste `uncategorized` sem Categoria atribuída (plano AC 18)
Proof: `./vendor/bin/pest --filter='none_applies_at_threshold_produces_uncategorized'`

**C19** - Quando a Revisão humana decide não afirmar uma Categoria, persiste `inconclusive` com um motivo permitido; confiança abaixo de `0.60` pode informar a proposta, mas não persiste o Estado oficial sem decisão humana (plano AC 19)
Proof: `./vendor/bin/pest --filter='uncertain_result_requires_inconclusive_reason'`

**C20** - Uma proposta de sobreposição estrutural é apresentada como conflito; somente quando a Revisão humana escolhe esse resultado são persistidos `inconclusive` e `inconclusive_reason=conflict` (plano AC 20)
Proof: `./vendor/bin/pest --filter='structural_overlap_produces_conflict'`

**C21** - IDs de Categoria desconhecidos e arquivados, inclusive em propostas de provedor, são rejeitados por ramificações de validação separadas e ficam ausentes do resultado oficial persistido pela Revisão humana (plano AC 21)
Proof: `./vendor/bin/pest --filter='unknown_and_archived_category_ids_are_rejected_before_persistence'`

**C22** - Uma falha do provedor após as tentativas configuradas marca o Estado operacional da Thread como `failed` e registra um erro tipado de retry, sem criar ou substituir o resultado oficial, `latest_official_classification_id` nem os Labels herdados; a Revisão humana continua disponível (plano AC 22)
Proof: `./vendor/bin/pest --filter='provider_failure_after_retries_produces_failed'`

### S4 - pré-análise Jev e Revisão humana · 10 verificações · 6 arquivos de testes futuros · ~16k

**C23** - Uma Thread nova ou pendente sem resultado oficial anterior é enfileirada para Pré-análise assíncrona somente após a persistência do sync e o avanço do Cursor, identificada por `(account_id, thread_id, thread_snapshot_hash, category_catalog_version, provider_model, privacy_policy_version)`; com Jev desabilitado ou `provider=none`, nenhum job é criado (plano AC 23)
Proof: `./vendor/bin/pest --filter='preanalysis_dispatch_requires_enabled_provider_and_cursor_commit'`

**C24** - Uma Thread com resultado oficial não recebe Pré-análise nem reclassificação automáticas após uma Message nova; mesmo que seu Estado operacional fique `pending`, o resultado oficial anterior e os Labels herdados permanecem até ação explícita de Revisão humana (plano AC 24)
Proof: `./vendor/bin/pest --filter='classified_thread_is_excluded_from_automatic_preanalysis'`

**C25** - Sugestões válidas do Jev referenciam versões ativas de Categoria e contêm probabilidades somente em `0..1` (plano AC 25)
Proof: `./vendor/bin/pest --filter='preanalysis_persists_allowlisted_bounded_suggestions'`

**C26** - Persistir uma proposta de Pré-análise deixa inalterados a Categoria, o Estado e o resultado oficial da Thread e da Message, assim como seus Labels; somente a Revisão humana pode escolher, confirmar ou corrigir e persistir o resultado oficial (plano AC 26)
Proof: `./vendor/bin/pest --filter='preanalysis_cannot_mutate_official_state'`

**C27** - Dados malformados do Jev, probabilidade fora do intervalo e ID de Categoria desconhecido falham de forma fechada sem uma sugestão inválida (plano AC 27)
Proof: `./vendor/bin/pest --filter='malformed_jev_output_is_rejected_fail_closed'`

**C28** - A listagem de Revisão expõe sugestões, probabilidades, incerteza e conflito sem aplicar uma sugestão (plano AC 28)
Proof: `./vendor/bin/pest --filter='review_queue_displays_advisory_preanalysis'`

**C29** - Somente a confirmação explícita da Revisão humana no escopo da Thread registra proveniência humana antes de persistir o resultado oficial da Thread e a Herança da Message (plano AC 29)
Proof: `./vendor/bin/pest --filter='thread_confirmation_records_human_provenance_first'`

**C30** - A Revisão humana registra a rejeição de uma Sugestão sem atribuição automática nem alteração do resultado oficial da Thread; somente uma escolha, confirmação ou correção humana explícita pode alterá-lo (plano AC 30)
Proof: `./vendor/bin/pest --filter='suggestion_rejection_records_human_decision_without_assignment'`

**C31** - Somente a correção explícita da Revisão humana no escopo da Message persiste uma divergência em relação ao resultado oficial da Thread (plano AC 31)
Proof: `./vendor/bin/pest --filter='message_scope_review_records_thread_divergence'`

**C32** - Uma Pré-análise com falha ou bloqueada pelo Gate mantém a Revisão humana disponível e não cria nem substitui o resultado oficial; se as tentativas configuradas do provedor se esgotarem, registra falha tipada e Estado operacional `failed`, preservando resultado oficial e Labels anteriores (plano AC 32)
Proof: `./vendor/bin/pest --filter='preanalysis_failure_keeps_manual_review_available'`

### S5 - ciclo de vida, superfícies e saída hostil de modelo · 15 verificações · 7 arquivos de testes futuros · ~18k

**C33** - `ecs:reclassify` usa versões atuais de Categoria e preserva resultados anteriores (plano AC 33)
Proof: `./vendor/bin/pest --filter='reclassify_preserves_prior_results_and_uses_current_versions'`

**C34** - A reclassificação não sobrescreve um resultado com proveniência humana (plano AC 34)
Proof: `./vendor/bin/pest --filter='human_review_result_is_adhesive_during_reclassification'`

**C35** - A retenção exclui um corpo bruto expirado preservando metadados, identidade da Thread e histórico de classificação (plano AC 35)
Proof: `./vendor/bin/pest --filter='retention_removes_body_preserves_metadata_and_history'`

**C36** - A limpeza total remove conteúdo de mensagens, tokens OAuth e payloads de provedores da instalação (plano AC 36)
Proof: `./vendor/bin/pest --filter='total_wipe_removes_installation_sensitive_data'`

**C37** - Um comando válido da CLI primária retorna saída `0`, enquanto uma invocação inválida retorna saída `2` sem chamada ao provedor (plano AC 37)
Proof: `./vendor/bin/pest --filter='cli_exit_codes_and_invalid_invocation_are_bounded'`

**C38** - O sync HTTP obrigatório da API na VPS, publicado por Nginx atrás de HTTPS/TLS, retorna `202` para trabalho assíncrono aceito e `400`, `409` ou `503` para as classes de falha nomeadas (plano AC 43)
Proof: `./vendor/bin/pest --filter='http_sync_returns_declared_status_codes'`

**C39** - A Revisão HTTP retorna sugestões sanitizadas somente após JWT Google OIDC válido, com issuer, audience, claims temporais, assinatura, discovery/JWKS, rotação de chaves e allowlist validados server-side (plano AC 38)
Proof: `./vendor/bin/pest --filter='http_review_requires_authentication_and_sanitization'`

**C40** - Instruções do modelo não podem escolher uma conta, tenant, papel, escopo de autorização ou efeito colateral (plano AC 39)
Proof: `./vendor/bin/pest --filter='model_output_has_no_authority_over_scope_or_side_effects'`

**C41** - A validação da allowlist de Categoria rejeita um ID desconhecido independentemente da confiança informada (plano AC 40)
Proof: `./vendor/bin/pest --filter='model_confidence_cannot_bypass_category_allowlist'`

**C42** - Uma referência de evidência não verificada é omitida do resultado em vez de inventada (plano AC 41)
Proof: `./vendor/bin/pest --filter='unverified_evidence_reference_is_omitted'`

**C43** - A proveniência do Jev via OpenRouter armazena o `OPENROUTER_MODEL` obrigatório, question, Category-version, policy-version e usage sem conteúdo bruto da requisição e sem seleção automática de modelo (plano AC 42)
Proof: `./vendor/bin/pest --filter='preanalysis_provenance_excludes_raw_request_body'`

**C44** - `ecs:classify` usa provedores configurados somente para gerar propostas consultivas, nunca persiste um resultado oficial nem aplica sugestões do Jev; a Revisão humana é a única writer oficial (plano AC 16-22, AC 37)
Proof: `./vendor/bin/pest --filter='classify_command_emits_advisory_proposal_only'`

**C45** - `GET /v1/reviews` retorna exatamente os resultados declarados `200`, `401` e `503` para os casos correspondentes de sucesso, JWT Google OIDC inválido e falha de serviço (plano AC 44)
Proof: `./vendor/bin/pest --filter='http_reviews_get_returns_declared_status_codes'`

**C46** - `POST /v1/reviews` retorna exatamente os resultados declarados `200`, `400`, `401`, `409` e `422` para os casos correspondentes de decisão, entrada, JWT Google OIDC inválido, conflito e validação (plano AC 45)
Proof: `./vendor/bin/pest --filter='http_reviews_post_returns_declared_status_codes'`

**C47** - `POST /v1/privacy-consents` retorna exatamente os resultados declarados `201`, `400`, `401` e `409` para os casos correspondentes de criação, entrada, JWT Google OIDC inválido e consentimento duplicado (plano AC 46)
Proof: `./vendor/bin/pest --filter='http_privacy_consents_returns_declared_status_codes'`

### S6 - exemplos revisados e recuperação híbrida · 7 verificações · 5 arquivos de testes futuros · ~8k

**C48** - Somente uma Revisão humana de Thread ou Mensagem, com opt-in ativo na Categoria, cria um Exemplo imutável vinculado à Revisão e à versão da Categoria; sem opt-in, nenhum Exemplo é promovido (plano AC 47)
Proof: `./vendor/bin/pest --filter='review_example_promotion_requires_category_opt_in_and_provenance'`

**C49** - A criação do Exemplo gera o embedding no serviço local e persiste o vetor e o índice léxico sem transmitir o conteúdo a um provedor externo (plano AC 48)
Proof: `./vendor/bin/pest --filter='example_embedding_and_lexical_index_are_local'`

**C50** - A recuperação consulta os canais semântico e léxico no PostgreSQL e retorna somente Exemplos ativos autorizados para a mesma instalação e Categorias elegíveis (plano AC 49)
Proof: `./vendor/bin/pest --filter='hybrid_example_search_uses_both_channels_and_scope'`

**C51** - A Pré-análise registra IDs e versões dos Exemplos recuperados para proveniência, sem persistir o texto recuperado como proveniência nem expô-lo pela API (plano AC 50)
Proof: `./vendor/bin/pest --filter='retrieved_example_provenance_stores_ids_and_versions_only'`

**C52** - Exemplos recuperados são tratados como entrada não confiável e o Gate inspeciona o payload completo Thread+Exemplos antes de qualquer chamada externa, respeitando política e consentimento (plano AC 51)
Proof: `./vendor/bin/pest --filter='privacy_gate_scans_full_thread_and_retrieved_examples'`

**C53** - Categorias arquivadas ou com opt-in desativado não fornecem Exemplos à recuperação; retenção e limpeza total removem ou invalidam texto e índices derivados conforme a política (plano AC 52)
Proof: `./vendor/bin/pest --filter='example_indexes_respect_category_state_retention_and_wipe'`

**C54** - A promoção ou recuperação de Exemplos não altera pesos do modelo nem executa fine-tuning (plano AC 53)
Proof: `./vendor/bin/pest --filter='review_examples_do_not_trigger_model_finetuning'`

### S7 - projeção restrita de Labels de Categoria no Gmail · 8 verificações · 4 arquivos de testes futuros · ~8k

**C55** - Para cada Categoria, a definição de Label customizada é reutilizada pelo nome exato ou criada uma vez; o mapeamento persiste o `labelId` estável (plano AC 54).
Proof: `./vendor/bin/pest --filter=category_label_mapping_reuses_or_creates_exact_name`

**C56** - Um resultado oficial categorizado projeta os Labels desejados em todas as Messages atuais da Thread, sem remover `INBOX` ou Labels não geridos (plano AC 55).
Proof: `./vendor/bin/pest --filter=official_thread_categories_project_to_all_messages_safely`

**C57** - Uma Message nova herda os Labels oficiais atuais da Thread; mesmo que a captura deixe a Thread `pending`, o resultado oficial anterior e os Labels herdados permanecem até ação explícita de Revisão humana, sem reclassificação automática (plano AC 56).
Proof: `./vendor/bin/pest --filter=new_message_inherits_thread_label_without_reclassification`

**C58** - Uma correção oficial restrita a uma Message altera somente os Labels geridos daquela Message (plano AC 57).
Proof: `./vendor/bin/pest --filter=message_scoped_correction_projects_only_to_target_message`

**C59** - `uncategorized` remove somente associações de Labels geridas; estados não oficiais e sugestões não iniciam escrita no Gmail (plano AC 58).
Proof: `./vendor/bin/pest --filter=uncategorized_clears_managed_labels_and_nonofficial_states_do_not_write`

**C60** - A reconciliação restaura uma associação gerida removida manualmente e substitui somente associações geridas obsoletas, preservando definições e Labels alheios (plano AC 59).
Proof: `./vendor/bin/pest --filter=category_label_reconcile_restores_and_replaces_managed_associations_only`

**C61** - A intenção pós-commit é idempotente e retryable; falha externa não reverte o resultado oficial local (plano AC 60).
Proof: `./vendor/bin/pest --filter=category_label_outbox_retries_without_rolling_back_classification`

**C62** - O writer aceita somente `gmail.modify` + `gmail.labels` e IDs customizados geridos; rejeita `mail.google.com` e IDs desconhecidos/de sistema, nunca remove `INBOX`, nem envia, arquiva ou apaga Messages (plano AC 61).
Proof: `./vendor/bin/pest --filter=category_label_writer_rejects_unmanaged_scope_and_operations`

## Coverage

| Conjunto (tamanho) | Membro -> prova | Unproven |
| --- | --- | --- |
| resultados de sync e captura (6) | primeiro C1 · incremental C2 · somente Labels C3 · histórico expirado C4 · versão de conteúdo C5 · Message nova em Thread com resultado oficial C6 | - |
| ações do Gate (4) | opt-in C9 · `abort` C10 · `flag` C11 · `redact` C12 | - |
| Estados operacionais da Thread (5) | `pending` C6 · `categorized` C17 · `uncategorized` C18 · `inconclusive` C19 · `failed` C22 | - |
| slugs operacionais reservados de Categoria (5) | `pending` C15 · `categorized` C15 · `uncategorized` C15 · `inconclusive` C15 · `failed` C15 | - |
| motivos de `inconclusive` (4) | `low_confidence` C19 · `conflict` C20 · `no_match` C19 · `insufficient_content` C19 | - |
| ramificações de ID de Categoria (3) | ativa C16 · arquivada C21 · desconhecida C21 | - |
| validação da saída de Categoria (4) | versão ativa C16 · conflito estrutural C20 · ID desconhecido C21 · probabilidade limitada C25 | - |
| escopo da Pré-análise automática (4) | após o Cursor C23 · nova/pendente sem resultado oficial anterior C23 · exclusão classificada C24 · impressão digital C23 | - |
| campos da tupla de idempotência da pré-análise (6) | `account_id` C23 · `thread_id` C23 · `thread_snapshot_hash` C23 · `category_catalog_version` C23 · `provider_model` C23 · `privacy_policy_version` C23 | - |
| ciclo de vida da Pré-análise (6) | provider habilitado/desabilitado C23 · sugestão válida C25 · nenhuma mutação oficial C26 · rejeição de malformação C27 · rejeição de Sugestão C30 · fallback manual C32 | - |
| alvos da Revisão (2) | Thread C29 · Mensagem C31 | - |
| operações de ciclo de vida (2) | retenção C35 · limpeza total C36 | - |
| campos de proveniência do Jev (5) | model C43 · question C43 · Category-version C43 · policy-version C43 · usage C43 | - |
| status de `POST /v1/sync` (4) | `202` C38 · `400` C38 · `409` C38 · `503` C38 | - |
| status de `GET /v1/reviews` (3) | `200` C45 · `401` C45 · `503` C45 | - |
| status de `POST /v1/reviews` (5) | `200` C46 · `400` C46 · `401` C46 · `409` C46 · `422` C46 | - |
| status de `POST /v1/privacy-consents` (4) | `201` C47 · `400` C47 · `401` C47 · `409` C47 | - |
| promoção de Exemplo (2) | opt-in ativo C48 · ausência de opt-in C48 | - |
| proveniência do Exemplo recuperado (2) | ID C51 · versão C51 | - |
| canais de recuperação (2) | semântico C50 · léxico C50 | - |
| escopo de recuperação (2) | instalação C50 · Categorias ativas/autorizadas C50 | - |
| membros de controle da saída hostil do modelo (6) | instrução C40 · conta C40 · tenant C40 · papel C40 · escopo de autorização C40 · efeito colateral C40 | - |
| fronteiras da saída hostil do modelo (5) | fonte de decisão C40 · allowlist C41 · evidência C42 · requisição bruta C43 · Exemplos recuperados C52 | - |
| decisões da projeção de Labels de Categoria no Gmail (8) | mapeamento estável de Label C55 · projeção herdada da Thread C56 · herança para Message nova C57 · correção restrita à Message C58 · `uncategorized` e estados não oficiais C59 · reconciliação de Labels obsoletas C60 · intenção durável e retry C61 · escopos OAuth e allowlist de operações/IDs C62 | - |

`Unproven: -` indica somente que o mapeamento não identificou uma lacuna entre
os subitens listados e os critérios associados. Não indica execução, aprovação
ou evidência dos checks.

Claims que nomeiam um código de status ou rota: C38 e C45-C47 têm provas de fronteira; C39 e C29-C30 provam os seams de autenticação/sanitização e decisão. A validação Google OIDC é afirmada em C39 e reutilizada nas rotas C45-C47. Todos os demais claims são provados em seu seam de decisão e não dependem de atravessar uma rota.

## Test policy

| Forma do código | Provas obrigatórias | Expectativa de cobertura |
| --- | --- | --- |
| Gate de privacidade e validador de saída, alcançados por uma fronteira de provedor | uma prova Pest no seam público do provedor e outra no seam de decisão | um caso afirmado para cada ação do Gate e cada membro de saída inválida listado em Coverage |
| Transição de Estado da Thread e Revisão humana, alcançadas por CLI/HTTP | uma prova Pest na superfície e outra no seam de decisão de Estado | cada Estado operacional, rejeição e os dois alvos de Revisão são afirmados independentemente |
| Idempotência da fila e ordenação do Cursor | uma prova Pest de integração no seam de sync e uma prova de decisão sobre a chave de idempotência | escopos nova/pendente/classificada e retry duplicado são casos afirmados separadamente |
| Pontos de entrada CLI/HTTP que decidem validade de entrada ou autorização | uma prova Pest de fronteira | entrada aceita, entrada inválida, entrada não autenticada e cada código de erro declarado |
| Parsing MIME, transporte HTTP e repasse do repositório sem ramificação | nenhuma prova isolada obrigatória | cobertos pela prova de decisão consumidora; sem teste tautológico de transporte |
| projeção restrita de Labels de Categoria | mapeamento por `labelId`, allowlist de operações, preservação de `INBOX`/Labels alheios e retry pós-commit | fakes do Gmail provam escrita limitada, reconciliação idempotente e falha sem rollback local |

Evidências:

- O plano enumera ações do Gate, Estados operacionais, alvos de Revisão, rejeição de Sugestão, status de rotas e fronteiras da saída hostil do modelo; cada item está associado acima.
- Os selectors nomeados de `--filter` do Pest são critérios planejados de prova. Nenhum C1–C62 foi executado; a evidência de migration test registrada acima não cobre esses critérios.

## Swept

- validation: C15, C21, C25, C27, C41, C46 (validação)
- failure modes: C4, C10, C22, C27, C32, C38, C45, C46, C47 (modos de falha)
- idempotency: C4, C23, C24 (idempotência)
- authorization: C9, C11, C39, C40, C45, C46, C47 (autorização)
- concurrency: C2, C23, C29 (concorrência)
- data lifecycle: C35, C36, C43, C48, C53 (ciclo de vida dos dados)
- dependency failure: C10, C22, C32, C38, C45, C46, C47 (falha de dependência)
- state transitions: C6, C17, C18, C19, C22, C26, C29, C30, C31, C34 (transições de Estado)
- observability: C13, C22, C43, C51 (observabilidade)

- Gmail category-label: C55–C62 cobrem mapeamento, resolução por Message, estados, autorização, preservação e retry/idempotência.

## Handoff

Somente fase de artefatos: `.specs/STATE.md`, `plan.md` e `checks.md`, além da documentação legada. Estimativa atualizada: `~10k + ~12k + ~15k + ~16k + ~18k + ~8k + ~8k = ~87k < 150k` de orçamento. O próximo builder pode implementar e executar as provas planejadas C1–C62 nos seams públicos nomeados; `PD-009` e `PD-010` não são blockers, e `PD-012` está resolvida. Registrar evidência por check: nenhum C1–C62 está `green` até sua execução real; a evidência de migration test existente não substitui essa execução. Um relatório de verificação depende da implementação e da execução do Verifier independente.
