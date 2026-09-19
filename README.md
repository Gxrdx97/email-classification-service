# email-classification-service

Serviço standalone, self-hosted e open source para **classificar automaticamente os e-mails de uma conta Gmail pessoal em categorias definidas por você**.

Somente leitura em relação ao Gmail (OAuth 2.0, `gmail.readonly`). Cotações/quotes, RAG e multi-tenancy não fazem parte deste projeto.

## Como funciona

```text
Gmail OAuth (server-side, gmail.readonly)
  → Sync (backfill + historyId incremental)
  → Captura canônica (mensagens/threads imutáveis)
  → Enriquecimento determinístico
  → Gate de privacidade (DLP + prompt injection)
  → Classificação (regras e/ou LLM adapter)
  → categorized | uncategorized | inconclusive | failed
  → Revisão / reclassificação
```

- **Categorias**: você cria quantas quiser (`ecs:categories:create`), com nome, descrição, exemplos e instrução opcional. O classificador só pode atribuir categorias existentes — nunca inventa.
- **Estados do sistema** (não são categorias): `pending`, `categorized`, `uncategorized`, `inconclusive`, `failed`.
- **Provedores de IA externos**: opcionais e desligados por padrão; todo conteúdo passa por um gate de privacidade antes de sair da máquina.

## Status

Em especificação — ver `docs/`:

| Documento | Conteúdo |
|---|---|
| `docs/proposal.md` | Escopo e limites da entrega |
| `docs/research.md` | Decisões (Gmail API vs IMAP, cursor, taxonomia) |
| `docs/design.md` | Contratos, modelo de dados, pipeline, config |
| `docs/security-envelope.md` | Ameaças e controles obrigatórios |
| `docs/specs/email-classification/spec.md` | Requisitos e cenários |
| `docs/tasks.md` | Plano de implementação (0/N) |
| `docs/smoke-tests.md` | Matriz de smoke tests |

## Licença

Apache-2.0. E-mails reais, tokens e dados pessoais nunca devem entrar neste repositório.
