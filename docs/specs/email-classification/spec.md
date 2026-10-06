# Spec — email-classification

Este documento é o índice legível da especificação. A fonte canônica de obrigações e provas é:

- Plano: [`../../../.specs/features/email-classification/plan.md`](../../../.specs/features/email-classification/plan.md)
- Checks: [`../../../.specs/features/email-classification/checks.md`](../../../.specs/features/email-classification/checks.md)
- Estado e decisões: [`../../../.specs/STATE.md`](../../../.specs/STATE.md)

O serviço é autônomo, auto-hospedado e single-tenant. No Gmail, além da captura, somente sincroniza Labels de Categoria de resultados oficiais revisados; não envia, arquiva nem apaga mensagens. A unidade
de classificação é a **Thread** inteira; veja `docs/adr/0001-classificacao-por-thread.md` e o
vocabulário em `CONTEXT.md`.

## Baseline de implementação

O objetivo é uma implementação completa em Laravel + Pest, com PostgreSQL,
Redis e Docker. A entrega inclui o núcleo do serviço, a CLI `ecs:*` e uma API
HTTP autenticada publicada em uma VPS por Nginx com HTTPS/TLS.

- A integração Gmail é real, usa `gmail.modify` para aplicar/remover Labels em
  mensagens e `gmail.labels` para listar/criar definições, com allowlist
  server-side limitada a Labels de Categoria geridos; inclui guia operacional
  para configurar o aplicativo OAuth do Google.
- A integração Jev é real e usa OpenRouter. `OPENROUTER_MODEL` é obrigatório;
  não existe seleção automática de modelo.
- O Gate de privacidade roda antes de qualquer provedor externo. Seu padrão é
  `abort`: dado sensível ou prompt injection detectado impede o envio da
  Thread e gera somente evento sanitizado.
- A API usa Bearer Google OIDC, distinto do OAuth do Gmail. O servidor valida
  issuer, audience, claims temporais, expiração, assinatura, discovery/JWKS,
  rotação de chaves e allowlist de identidades autorizadas.

Durante o bootstrap, o implementador selecionará e fixará versões compatíveis
de PHP/Laravel, pacotes dos clientes Google e OAuth/OIDC e o cliente HTTP do
OpenRouter, após validar a documentação oficial. Não haverá seleção automática
dessas dependências em runtime. Nginx está definido como proxy reverso; o
registro final de audience e allowlist OIDC permanece pendente.

`PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
bootstrap. Essa dispensa não significa que testes ou checks passaram, não afirma
que a atividade preparatória foi concluída e não cria o `validate_checks.py`,
que continua ausente. Credenciais reais, domínio/DNS/TLS, credenciais OpenRouter
e segredos, armazenamento e backups da VPS são pré-requisitos operacionais e
não devem ser registrados no repositório.

## Requirements

### R1 — Conexão Gmail e escopos OAuth

O serviço DEVE conectar à conta Gmail do Usuário via OAuth 2.0 no servidor,
solicitando e validando `gmail.modify` e `gmail.labels`, sem solicitar
`mail.google.com`. O state é de uso único, o redirect é fixo e o refresh token
é cifrado. Uma allowlist server-side limita as escritas à gestão e aplicação
dos Labels de Categoria definidos nesta especificação; envio, arquivamento e
exclusão de mensagens são proibidos.

### R2 — Sincronização e Cursor

O serviço DEVE executar carga inicial com `messages.list/get` e sincronização incremental com `history.list`,
mantendo um Cursor por conta. O Cursor só avança após persistência confirmada. `history_id` expirado
exige resync completo idempotente.

### R3 — Captura canônica

Mensagens e Threads DEVEM ser armazenadas de forma imutável, deduplicadas por account + provider
message id. Mudança de conteúdo cria nova Versão da mensagem; mudança apenas de Labels atualiza
metadado vivo. Anexos são somente metadados.

### R4 — Enriquecimento determinístico

O serviço DEVE produzir texto normalizado, remoção de respostas citadas e assinaturas, Participantes,
direção, idioma, posição na Thread e proveniência do extrator. Conteúdo de Anexo nunca é lido.

### R5 — Categorias versionadas

O Usuário DEVE poder criar, editar, arquivar e consultar Categorias com nome, slug único, descrição,
Exemplos e instruction opcional. Cada edição cria uma versão imutável. Slugs do Estado operacional
(`pending`, `categorized`, `uncategorized`, `inconclusive`, `failed`) são reservados.

### R6 — Propostas de classificação e decisão oficial por Thread

Classificadores e provedores DEVEM receber a Thread enriquecida inteira e Categorias ativas e podem
produzir somente uma proposta consultiva com `categories[]`, `confidence`, `review_required`,
`reason_codes[]` e `evidence_refs[]`. Somente a Revisão humana escolhe, confirma ou corrige a proposta
e persiste o resultado oficial na Thread; Mensagens herdam essa decisão oficial. Múltiplas Categorias
são permitidas.

- Proposta com Categoria válida indica `categorized`; só a Revisão humana pode torná-la resultado oficial e gerar Herança.
- Afirmação explícita de vazio com confiança `>= 0.60` pode propor `uncategorized`; a persistência oficial exige Revisão humana.
- Abstenção abaixo do limiar, conteúdo insuficiente, saída vazia sem confiança ou conflito pode propor
  `inconclusive` com `inconclusive_reason` obrigatório.
- Sobreposição estrutural pode propor `inconclusive` com `conflict`.
- ID inexistente ou arquivado é rejeitado antes da persistência.
- Falha técnica após retries registra a tentativa/job como `failed`; isso não substitui nem sobrescreve
  uma decisão oficial e mantém a Revisão manual disponível.
- Mensagem nova em Thread classificada pode tornar o Estado operacional da Thread `pending`, mas a
  última decisão oficial continua governando os Labels herdados até ação explícita da Revisão humana.
  Não há reclassificação automática; `ecs:reclassify` é explícito.

### R7 — Provedores de classificação

O serviço DEVE suportar regras determinísticas e LLM externo configurável como geradores de propostas
consultivas; nenhum deles escolhe ou persiste o resultado oficial. Os
adaptadores reais de Gmail e OpenRouter DEVEM ser configuráveis desde o
bootstrap, mas chamadas reais DEVEM exigir opt-in explícito e segredos fora do
repositório. O provedor externo fica desligado por padrão na suíte normal. A
proposta estruturada é validada contra a allowlist no servidor; nenhum modelo pode
escolher conta, escopo, Categoria ou side effect.

### R8 — Gate de privacidade

Antes de qualquer envio externo, o serviço DEVE executar DLP e detecção local de prompt injection.
A política é `redact|flag|abort`; `flag` exige consentimento explícito por Thread; `abort` faz zero
chamadas; `redact` envia somente a representação permitida. Corpo de e-mail, token, PII, request e
response brutos não entram em logs, erros ou fixtures.

### R9 — Pré-análise Jev

O serviço DEVE tratar Jev como `PreAnalysisProvider` opcional e não autoritativo. Após cada sync,
depois de persistir captura e Cursor, uma tarefa assíncrona PODE ser enfileirada somente quando Jev
estiver habilitado e somente para Threads novas ou pendentes sem resultado oficial anterior.
`provider=none` não cria job. Threads com resultado oficial não entram automaticamente.

Uma Pré-análise válida DEVE persistir Sugestões de categoria separadas da Classificação oficial,
com Category version, probability `0..1`, incerteza, proveniência de model/question/policy e usage.
Nenhuma Sugestão altera Estado operacional ou Herança antes de Revisão humana. Resposta inválida,
ID desconhecido, probabilidade fora de `0..1`, timeout ou falha deixam Revisão manual disponível.

### R10 — Revisão humana

O Usuário DEVE ver sugestões, probabilidades, incerteza e conflito antes de decidir. Confirmar ou
corrigir na Thread registra provenance humana antes de alterar o resultado oficial e a Herança.
Correção restrita a uma Message registra divergência em relação à Thread. Rejeitar uma sugestão não
cria atribuição automática.

### R11 — Reclassificação e exemplos

`ecs:reclassify` DEVE usar as versões atuais das Categorias e preservar o histórico. Resultados com
provenance humana são adesivos e não são sobrescritos. Correções só viram Exemplo quando o recurso
está habilitado por Categoria, criando nova versão. Exemplos elegíveis PODEM ser recuperados por busca
lexical PostgreSQL e semântica `pgvector`; embeddings são gerados localmente e usados como contexto em
inferência, sem fine-tuning nem atualização de pesos nesta entrega.

### R12 — Operação e qualidade

CLI é a interface primária: connect, sync, enrich, review, classify, categories, reclassify,
retenção e wipe. A API HTTP autenticada é obrigatória para sync, Threads, sugestões, Categorias,
consentimento e Revisão; ela roda na VPS atrás de Nginx com HTTPS/TLS e Bearer Google OIDC.
O ambiente de execução dos testes é Laravel/PHP + Pest com perfil Spec Lean `standard`; fakes e
fixtures sintéticas são obrigatórios. Evals reais e dados reais ficam fora da suíte normal.

### R13 — Recuperação híbrida de Exemplos

O sistema DEVE buscar Exemplos elegíveis pelos dois canais: full-text lexical no PostgreSQL e
similaridade semântica em `pgvector`. Embeddings DEVEM ser gerados localmente. A recuperação DEVE
limitar-se a Exemplos de Revisões humanas promovidos por opt-in da Categoria e a escopo/versões ativos;
IDs e versões recuperados DEVEM ser registrados como proveniência da Pré-análise. O Gate DEVE avaliar o
payload completo da Thread e dos Exemplos antes do envio externo. Exemplos e embeddings DEVEM ser
tratados como dados derivados sensíveis, sem exposição em API ou logs, e sujeitos a retenção e wipe.
Exemplos recuperados são contexto de inferência; fine-tuning e atualização de pesos ficam fora da entrega.
Runtime/modelo/dimensão de embedding, fusão, top-k e comportamento diante de falha por canal permanecem
pendentes em `PD-011`.

### R14 — Labels de Categoria no Gmail

Após uma decisão oficial revisada, o serviço DEVE criar ou reutilizar um Label
personalizado do Gmail com o nome visível da Categoria e persistir seu ID
estável associado à Categoria. Para `categorized`, DEVE aplicar os Labels
correspondentes a cada Mensagem da Thread; uma correção oficial restrita a uma
Mensagem afeta somente essa Mensagem. Mensagens futuras DEVEM receber os Labels
da última atribuição oficial herdada. Uma Message nova pode tornar a Thread
operacionalmente `pending`, mas essa última decisão continua governando os
Labels herdados até ação explícita da Revisão humana, sem disparar classificação
ou reclassificação automática.

Um resultado oficial `uncategorized` DEVE remover somente associações de Labels
de Categoria geridas pelo serviço. `pending`, `inconclusive`, `failed` e
Sugestões de Pré-análise não iniciam nova decisão ou alteração de Labels; uma
Message nova ainda herda os Labels da última decisão oficial. Reclassificação e
reconciliação DEVEM substituir/restaurar somente associações geridas,
preservando Labels alheios e definições existentes; nunca podem remover
`INBOX`, enviar, arquivar ou apagar mensagens. A intenção de sincronização é
persistida após a decisão oficial, executada de modo idempotente e retryável;
falha externa não desfaz o resultado oficial nem pode ser reportada como sync
bem-sucedido.

## Recuperação restrita e limite de segurança

O produto permite somente recuperação híbrida de Exemplos originados de Revisões humanas e habilitados
por Categoria; RAG genérico, AGE, agents, memória e fine-tuning ficam fora do escopo. Embeddings e
Exemplos são dados derivados sensíveis, guardados apenas nos índices PostgreSQL da instalação e nunca
expostos por API/logs. A geração de embeddings é local. Exemplos recuperados são conteúdo não confiável;
o Gate deve inspecionar o payload completo (Thread + Exemplos) antes de qualquer envio ao Jev. Retenção,
arquivamento, revogação do opt-in e wipe devem impedir uso indevido ou remover os índices aplicáveis.

## Glossary

Vocabulário canônico: [`CONTEXT.md`](../../../CONTEXT.md) na raiz.
