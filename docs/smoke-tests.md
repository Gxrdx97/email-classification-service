# Testes de fumaça — email-classification-service

`PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
bootstrap; isso não significa que testes/checks passaram nem que o
`validate_checks.py` ausente existe. Esta matriz descreve cenários, não afirma
que foram executados. O provedor externo fica **desligado** por padrão; fixtures `.eml` são sintéticas;
nenhum dado real, token, corpo ou resposta de provedor entra no repositório. A
suíte normal usa Laravel/Pest e fakes; evals externos são opt-in. Os adaptadores
reais de Gmail e OpenRouter podem ser configurados desde o bootstrap, mas
chamadas reais exigem opt-in explícito e segredos fora do repositório. A API de
implantação usa HTTPS atrás de Nginx e exige `OPENROUTER_MODEL` quando OpenRouter
estiver habilitado.

## Matriz

| # | Cenário | Esperado |
|---|---|---|
| 1 | Instalação limpa + migrations | Esquema criado; nenhuma conta ativa |
| 2 | `ecs:connect` (fake OAuth) | Conta ativa com `gmail.modify` + `gmail.labels`; rejeitar `mail.google.com`; token cifrado |
| 3 | OAuth com escopo insuficiente | Erro tipado; conta inválida; sem sync |
| 4 | OAuth state replay/redirect inválido | Callback rejeitado; nenhum token persistido |
| 5 | Backfill fake de 30 mensagens | Mensagens/Threads imutáveis; dedupe; Cursor avança após persistência |
| 6 | Sync incremental com history válido | Só novidades capturadas; sem duplicatas |
| 7 | `history_id` expirado | Resync completo idempotente |
| 8 | Mudança somente de Labels | Labels atualizados; nenhuma nova Versão da mensagem |
| 9 | Mudança de conteúdo | Nova Versão criada; anterior preservada; Thread afetada fica `pending` |
| 10 | Sync com falha parcial | Cursor não avança antes da persistência confirmada |
| 11 | Mensagem com quoted reply + assinatura | Texto enriquecido sem citação; fatos versionados |
| 12 | Anexo presente | Só nome/media type/tamanho persistidos; conteúdo nunca lido |
| 13 | Criar Categoria `viagem` | Ativa, versionada e elegível |
| 14 | Criar slug operacional reservado | Rejeitado com erro explícito |
| 15 | Definições sobrepostas | Aviso explícito; proposta de classificação pode indicar `conflict` |
| 16 | Classificador por regras avalia uma Thread | Proposta `categorized`; somente Revisão humana escolhe/confirma/corrige e persiste resultado oficial e Herança |
| 17 | Nenhuma Categoria se aplica | Proposta `uncategorized` somente com afirmação e confiança `>= 0.60`; resultado oficial exige Revisão humana |
| 18 | Confiança baixa/saída vazia | Proposta `inconclusive` + reason permitido; resultado oficial exige Revisão humana |
| 19 | Conflito estrutural | Proposta `inconclusive` + `inconclusive_reason=conflict`; resultado oficial exige Revisão humana |
| 20 | Provedor de classificação retorna ID inexistente/arquivado | ID não persiste; reason registrado sem conteúdo do provedor |
| 21 | Provedor de classificação falha após novas tentativas | Tentativa/job `failed` com retry/error tipado; decisão oficial anterior preservada e Revisão manual disponível |
| 22 | Provedor externo desligado (`pre_analysis.provider=none`) | Nenhuma chamada externa nem job de Pré-análise Jev; fluxo local disponível |
| 23 | DLP `abort` | Nenhum envio externo; Revisão manual disponível |
| 24 | DLP `redact` | Provedor recebe apenas representação redigida |
| 25 | DLP/injection `flag` | Nenhum envio até consentimento explícito da Thread |
| 26 | Sync de Thread nova/pending, sem resultado oficial anterior e com Jev habilitado | Cursor persiste primeiro; uma pré-análise assíncrona é enfileirada |
| 27 | Sync de Thread já classificada | Nenhuma pré-análise ou reclassificação automática; nova Message pode tornar Thread `pending`, mas herda Labels da última decisão oficial até ação explícita |
| 28 | Jev retorna sugestões válidas | Probabilidades `0..1`; IDs ativos; Pre-análise separada |
| 29 | Jev retorna instrução/ID inválido/probabilidade inválida | Resultado rejeitado fail-closed; Estado oficial não muda |
| 30 | Lista de Revisão | Sugestões, probabilidades, incerteza e conflito visíveis; nada aplicado |
| 31 | Confirmação de sugestão na Thread | Revisão humana registrada antes do resultado oficial e Herança |
| 32 | Correção restrita à Message | Divergência em relação à Thread registrada |
| 33 | Falha/timeout/429 do Jev | Revisão manual continua disponível; Estado oficial intacto |
| 34 | Jev aceita após `flag` | Consentimento da Thread auditado; request bruto não persistido |
| 35 | Modelo inventa conta/tenant/escopo/side effect | Saída tratada como dado; nenhuma autorização ou ação |
| 36 | Evidence ref sem provenance | Referência omitida; nenhuma evidência inventada |
| 37 | Reclassificar após editar Categoria | Nova versão usada; histórico preservado |
| 38 | Resultado corrigido por humano | Reclassificação não sobrescreve a correção |
| 39 | Retenção expira | Corpo bruto removido; metadados e histórico preservados |
| 40 | Wipe total | Conteúdo, tokens e payloads de provider removidos |
| 41 | Logs/erros/cache/HTTP | Sem corpo, token, PII, request/response bruto ou embedding |
| 42 | Isolamento single-tenant | Nenhum cache/leitura/serialização usa valor escolhido pelo modelo |
| 43 | CLI inválida | Código de saída `2`; nenhum provedor chamado |
| 44 | API da VPS sem JWT Google OIDC válido | `401`; nenhum dado de Thread retornado; HTTPS e o proxy não bypassam a autenticação |
| 45 | Evals Pest com opt-in | Conjunto holdout sintético; avaliador valida allowlist; nenhum segredo |
| 46 | Proposta automática sem Revisão humana | Permanece consultiva; nenhum resultado oficial ou Herança é persistido |
| 47 | Revisão humana com opt-in por Categoria | Exemplo versionado vinculado à Revisão e à versão da Categoria |
| 48 | Revisão sem opt-in | Nenhum Exemplo ou índice derivado criado |
| 49 | Indexação de Exemplo | Embedding gerado localmente; índice lexical/semântico persistido sem chamada externa |
| 50 | Busca híbrida de Exemplos | PostgreSQL full-text e `pgvector`; somente instalação e Categorias ativas com opt-in |
| 51 | Proveniência da recuperação | IDs/versões dos Exemplos usados registrados; texto e embedding ausentes da proveniência/API |
| 52 | Gate com Exemplos recuperados | DLP/injection avaliam Thread+Exemplos completos antes de qualquer envio ao Jev |
| 53 | Opt-out, arquivamento, retenção e wipe | Exemplos inelegíveis excluídos; texto e índices derivados removidos/invalidado |
| 54 | Aprendizado de Exemplos | Correção e recuperação não executam fine-tuning nem atualizam pesos |

| 55 | `ecs:connect` (fake OAuth) para Labels de Categoria | Consentimento solicita `gmail.modify` + `gmail.labels`, rejeita `mail.google.com` e mantém o token cifrado |
| 56 | Criar/reutilizar definição de Label | Categoria oficial tem nome visível | Reutiliza Label customizada de nome exato ou cria uma e persiste `labelId` estável |
| 57 | Projetar categoria oficial de Thread | Thread revisada e categorizada com várias Messages | Aplica as Labels desejadas em todas as Messages, preservando `INBOX` e Labels não geridos |
| 58 | Capturar Message em Thread classificada | Thread tem resultado oficial categorizado | Message herda Labels oficiais sem reclassificação automática, ainda que estado operacional fique `pending` |
| 59 | Corrigir uma Message oficialmente | Revisão é restrita à Message | Somente essa Message recebe a projeção corrigida; Messages irmãs não mudam |
| 60 | Tratar `uncategorized` e estados não oficiais | Resultado oficial vazio ou estado/sugestão não oficial | Remove apenas associações geridas para `uncategorized`; estados/sugestões não iniciam nova alteração, e Message nova `pending` herda Labels da última decisão oficial até ação explícita |
| 61 | Reconciliar remoção manual/reclassificação | Mapeamento Category↔Label existe | Restaura associação gerida desejada ou remove associações geridas obsoletas, sem alterar Labels alheios ou `INBOX`; idempotente |
| 62 | Falha/retry e limite do writer | Gmail fake falha e depois aceita retry; fornecer IDs de sistema/não geridos e operações proibidas | Resultado oficial local permanece; retry converge; rejeita IDs não geridos e nunca envia/arquiva/apaga |

## Casos de segurança da recuperação de Exemplos

O produto não reutiliza o RAG genérico nem seus agents/memória; a única recuperação é híbrida e restrita a Exemplos de Revisões humanas opt-in. Texto recuperado continua não confiável: Gate, escopo single-tenant, proveniência e serialização devem impedir que qualquer conteúdo recupere autoridade sobre conta, tenant, escopo, Usuário, Categoria ou side effect.

O smoke de autenticação deve cobrir issuer, audience, expiração e demais
claims temporais, assinatura, discovery/JWKS, rotação de chaves e allowlist de
identidades. O smoke de privacidade mantém `abort` como padrão e verifica que
uma detecção de dado sensível ou prompt injection produz apenas evento
sanitizado, sem chamada externa.

As provas de recuperação também verificam isolamento dos índices PostgreSQL,
geração local de embeddings, ausência de texto/vetores em logs e respostas,
aplicação do Gate ao payload Thread+Exemplos e remoção dos dados derivados no
ciclo de vida.
