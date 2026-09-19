# Smoke Tests — email-classification-service

Pré-requisitos: flags de provedor externo **desligadas** por default; fixture sintética `.eml`; nenhum dado real no repositório.

## Matriz

| # | Cenário | Esperado |
|---|---|---|
| 1 | Instalação limpa + migrations | Schema criado; nenhuma conta ativa |
| 2 | `ecs:connect` (fake OAuth) | Conta ativa com scopes `gmail.readonly`; token cifrado |
| 3 | OAuth escopo insuficiente (fake) | Erro tipado; conta inválida; sem sync |
| 4 | Backfill fake 30 mensagens | Mensagens/threads imutáveis; dedupe; cursor avança só após persistência |
| 5 | Sync incremental (fake history) | Só novidades capturadas; sem duplicatas |
| 6 | `history_id` expirado | Resync completo idempotente |
| 7 | Mensagem com quoted reply + assinatura | Texto enriquecido sem citação; fatos versionados |
| 8 | Anexo presente | Só metadados persistidos (nome/mime/tamanho) |
| 9 | Criar categoria `viagem` | Ativa, versionada, elegível |
| 10 | Criar categoria slug `uncategorized` | Rejeitada com erro explícito |
| 11 | Classificar com regras (match) | Estado `categorized`; IDs/versões referenciados |
| 12 | Nada se aplica | Estado `uncategorized` |
| 13 | Confiança baixa | Estado `inconclusive` + `low_confidence` |
| 14 | Classifier retorna ID inexistente | ID descartado; reason code registrado |
| 15 | Provedor LLM desligado (default) | Nenhuma chamada externa; regras locais apenas |
| 16 | Provedor LLM ligado + DLP abort | Sem envio; `inconclusive` com reason |
| 17 | Prompt injection no corpo | Flag registrada; conteúdo não vai cru ao provedor |
| 18 | Correção humana | Proveniência humana; estado `categorized` |
| 19 | Reclassificar após editar categoria | Nova versão usada; histórico preservado |
| 20 | Retenção expira | Corpo bruto removido; metadados/classificação preservados |
| 21 | Wipe total | Nenhum dado de mensagem/token restante |
| 22 | Evals opt-in | Scorer valida IDs contra categorias do fixture; sem chaves sensíveis |
