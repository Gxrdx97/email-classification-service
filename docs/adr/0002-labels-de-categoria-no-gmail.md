# 0002 — Labels de Categoria como projeção no Gmail

- **Status**: Aceito (2026-10-03)
- **Contexto**: O serviço precisa deixar mensagens organizadas de forma visível no Gmail. O desenho anterior era somente leitura, enquanto a classificação oficial continua determinada por Revisão humana e registrada na Thread ou, excepcionalmente, em uma correção restrita à Message.
- **Decisão**: Projetar cada Categoria oficial revisada como uma Label customizada do Gmail com o mesmo nome visível. Reutilizar ou criar a definição e persistir seu `labelId`; aplicar/remover associações por Message usando `users.messages.modify`. A Thread define as Labels herdadas pelas Messages, exceto onde exista correção oficial restrita à Message. Limitar o OAuth a `gmail.modify` e `gmail.labels`, com allowlist server-side para IDs geridos e operações de associação. Preservar `INBOX` e Labels alheios; nunca enviar, arquivar, apagar, renomear ou remover definições de Labels.
- **Consequências**: Labels do Gmail são uma projeção, nunca fonte da verdade da classificação. Uma falha externa não reverte o resultado oficial local: a intenção pós-commit deve ser reconciliada com retry e idempotência. Messages futuras herdam Labels da Thread sem disparar reclassificação. A permissão OAuth é mais ampla do que as operações do produto, portanto o writer e seus testes devem rejeitar IDs/ações fora da allowlist.

## Referências

- [ADR 0001 — Classificação por thread, não por mensagem](0001-classificacao-por-thread.md)
- [Envelope de segurança](../security-envelope.md)
- [Gmail API — Labels](https://developers.google.com/workspace/gmail/api/guides/labels)
- [Gmail API — `users.messages.modify`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/modify)
