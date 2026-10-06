# 0001 — Classificação por thread, não por mensagem

- **Status**: Aceito (2026-09-20)
- **Contexto**: O spec original (R6) classificava cada mensagem enriquecida isoladamente, mas o enriquecimento já rastreava posição na thread e a correlação de threads existia no modelo. Classificar mensagem a mensagem perde o contexto das respostas anteriores, gera resultados divergentes dentro da mesma thread e multiplica trabalho de revisão.

- **Decisão**: A **thread** é a unidade de classificação. O resultado (`categories[]`, `confidence`, estado operacional) vive na thread; cada mensagem individual **herda** o estado e as categorias da thread, que é a fonte de verdade. O classificador recebe a thread completa enriquecida (com truncação configurável quando exceder o limite do provedor). Correções de revisão podem ser aplicadas à thread ou restringidas a uma mensagem individual — nesse caso a divergência fica registrada. Nova mensagem numa thread classificada faz a thread voltar a `pending`; reclassificação acontece somente sob comando do usuário, e resultados corrigidos por humanos são adesivos.

- **Consequências**:
  - Classificações referenciam `thread_id` (não `message_id` como base da verdade); jobs de classificação/reclassificação operam por thread.
  - Custo de contexto por chamada ao provedor aumenta com threads longas → política de truncação configurável.
  - Revisão por mensagem cria divergência registrada entre mensagem e thread — precisa de representação explícita no modelo de dados.
  - Nenhuma reclassificação automática por chegada de mensagem; `pending` é o estado visível até o usuário rodar `ecs:reclassify`.
