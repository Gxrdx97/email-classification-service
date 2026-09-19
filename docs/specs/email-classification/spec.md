# Spec — email-classification

## Purpose

Classificar automaticamente os e-mails de uma conta Gmail pessoal em **categorias definidas pelo usuário**, com captura canônica, enriquecimento determinístico, controle de privacidade e revisão humana. Serviço standalone, self-hosted, open source, somente leitura em relação ao Gmail.

## Requirements

### R1 — Conexão Gmail (OAuth, somente leitura)

O serviço DEVE conectar à conta Gmail do operador via OAuth 2.0 server-side solicitando apenas `gmail.readonly`.

#### Scenario: Conectar conta

- **WHEN** o operador executa `ecs:connect` e conclui o consentimento do Google
- **THEN** o serviço armazena a conexão (subject, scopes concedidos, refresh token cifrado) e marca a conta ativa
- **AND** nenhum escopo de escrita é solicitado

#### Scenario: Escopo insuficiente

- **WHEN** o Google concede menos escopos que `gmail.readonly`
- **THEN** a conexão fica em estado inválido com erro tipado e a sincronização não é iniciada

### R2 — Sincronização

O serviço DEVE sincronizar mensagens usando backfill inicial (`messages.list/get`) e incremental (`history.list` a partir de `history_id`), com um cursor por conta.

#### Scenario: Primeira sincronização

- **WHEN** o operador executa `ecs:sync` na primeira vez
- **THEN** o serviço realiza backfill respeitando rate limits e persiste mensagens/threads imutáveis dedupe por provider message id

#### Scenario: Sync incremental

- **WHEN** um sync incremental roda e o `history_id` é válido
- **THEN** apenas mudanças novas são capturadas e o cursor avança somente após persistência confirmada

#### Scenario: Histórico expirado

- **WHEN** o `history_id` não é mais aceito pelo Gmail
- **THEN** o serviço executa resync completo idempotente sem duplicar mensagens

### R3 — Captura canônica

O serviço DEVE armazenar mensagens e threads de forma imutável (corpo bruto, headers relevantes, labels, direção, hash de conteúdo, metadados de anexos sem conteúdo).

#### Scenario: Mensagem duplicada

- **WHEN** a mesma mensagem chega novamente no sync
- **THEN** nenhuma duplicata é criada (unique account+provider_message_id)

### R4 — Enriquecimento determinístico

O serviço DEVE produzir, por mensagem e com versão de extrator, ao menos: texto normalizado (MIME/HTML→texto), remoção de respostas citadas e assinaturas, participantes normalizados, direção, idioma e posição na thread.

#### Scenario: Resposta citada

- **WHEN** o corpo contém uma resposta citada clássica (`On ... wrote:` / `>`)
- **THEN** o texto enriquecido exclui a citação e registra o fato com proveniência determinística

### R5 — Categorias definidas pelo usuário

O serviço DEVE permitir criar, atualizar e arquivar categorias (nome, slug único, descrição, exemplos opcionais, instrução opcional) com versionamento imutável das definições.

#### Scenario: Criar categoria

- **WHEN** o operador cria uma categoria `viagem`
- **THEN** ela fica ativa, versionada e imediatamente elegível para classificação

#### Scenario: Slug reservado

- **WHEN** o operador tenta criar categoria com slug `uncategorized`, `inconclusive`, `pending`, `categorized` ou `failed`
- **THEN** a operação é rejeitada com erro explícito

### R6 — Classificação

O serviço DEVE classificar cada mensagem enriquecida contra as categorias ativas, permitindo múltiplas categorias por mensagem, e produzir resultado estruturado (`categories[]`, `confidence`, `review_required`, `reason_codes[]`, `evidence_refs[]`).

#### Scenario: Mensagem categorizada

- **WHEN** o classificador atribui uma ou mais categorias válidas
- **THEN** o estado da mensagem passa a `categorized` e o resultado referencia as versões das definições usadas

#### Scenario: Nada se aplica

- **WHEN** nenhuma categoria se aplica e a confiança é aceitável
- **THEN** o estado é `uncategorized` sem categorias atribuídas

#### Scenario: Abstenção

- **WHEN** há conflito, baixa confiança ou conteúdo insuficiente
- **THEN** o estado é `inconclusive` com `inconclusive_reason` obrigatório (`low_confidence|conflict|no_match|insufficient_content`)

#### Scenario: Categoria inexistente na saída

- **WHEN** o classificador retorna um ID de categoria inexistente/arquivada
- **THEN** o ID é descartado e registrado como reason code, nunca persistido como classificação

#### Scenario: Erro técnico

- **WHEN** o adapter de classificação falha (rede, provedor indisponível)
- **THEN** o estado é `failed` e o job é retentado com backoff

### R7 — Provedores de classificação configuráveis

O serviço DEVE suportar classificação por regras determinísticas e por LLM externo (adapter configurável pelo operador), com o provedor externo **desabilitado por padrão**.

#### Scenario: Provedor externo opt-in

- **WHEN** o operador habilita um provedor externo
- **THEN** o conteúdo enviado passa antes pelo gate de privacidade (DLP + injeção) conforme a política configurada

### R8 — Privacidade

O serviço DEVE aplicar DLP e detecção de prompt injection antes de qualquer envio externo, com política por operador (`redact|flag|abort`), retenção configurável de corpo bruto e comando de exclusão total.

#### Scenario: Conteúdo sensível detectado

- **WHEN** o DLP identifica padrão de segredo/PII conforme política `abort`
- **THEN** a mensagem não é enviada a nenhum provedor externo e a classificação fica `inconclusive` com reason apropriado

### R9 — Revisão e reclassificação

O serviço DEVE permitir que o operador corrija categorias de uma mensagem e reclassifique em lote usando a versão atual das definições.

#### Scenario: Correção humana

- **WHEN** o operador corrige a categoria de uma mensagem
- **THEN** a correção é registrada com proveniência humana e o estado passa a `categorized`

#### Scenario: Reclassificação em lote

- **WHEN** o operador muda uma definição de categoria e executa `ecs:reclassify`
- **THEN** mensagens afetadas são reclassificadas contra a nova versão, preservando o histórico das classificações anteriores

### R10 — Operação

O serviço DEVE expor CLI para connect/sync/enrich/classify/categories/review/reclassify e limpeza de retenção; interface HTTP local é opcional e autenticada.

#### Scenario: Retenção

- **WHEN** o período de retenção de corpo bruto expira para uma mensagem
- **THEN** o corpo bruto é removido (metadados e classificações preservados)

## Glossary

- **Estado operacional**: `pending | categorized | uncategorized | inconclusive | failed` — namespace reservado do sistema, não é categoria.
- **Categoria**: definição criada pelo usuário, versionada, elegível para classificação.
- **Cursor**: `history_id` único por conta Gmail.
- **Gate de privacidade**: DLP + prompt injection antes de provedor externo.
