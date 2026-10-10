# email-classification-service

Serviço autônomo, auto-hospedado e de código aberto para **gerar propostas de classificação para os e-mails de uma conta Gmail pessoal em categorias definidas por você**, com resultado oficial escolhido, confirmado ou corrigido e persistido pela Revisão humana.

O serviço captura mensagens, gera propostas de classificação e sincroniza com o Gmail somente Labels de Categoria derivados de decisões oficiais revisadas. Mensagens permanecem na Inbox: o serviço não envia, arquiva nem apaga e-mails. Cotações/quotes, RAG genérico e multi-tenancy não fazem parte deste projeto. A única recuperação é híbrida e restrita a Exemplos de Revisões humanas com opt-in por Categoria; as regras para conteúdo não confiável também cobrem e-mails, Exemplos recuperados e saída de modelos.

## Baseline de implementação

O objetivo é uma implementação completa em Laravel + Pest, com PostgreSQL,
Redis e Docker. A entrega inclui o núcleo do serviço, a CLI `ecs:*` e uma API
HTTP autenticada executada em uma VPS.

- Gmail real usa OAuth server-side com `gmail.modify` para aplicar/remover Labels
  em mensagens e `gmail.labels` para listar/criar definições. A allowlist da
  aplicação limita escritas a Labels de Categoria geridos pelo serviço; não há
  operações de envio, arquivamento ou exclusão. A documentação inclui o guia
  operacional para configurar o aplicativo OAuth do Google.
- Jev real é integrado via OpenRouter. `OPENROUTER_MODEL` é obrigatório; o
  serviço nunca escolhe um modelo automaticamente.
- O Gate de privacidade roda antes de qualquer provedor externo. O padrão é
  `abort`: uma Thread com dado sensível ou prompt injection detectado não é
  enviada, e somente um evento sanitizado é registrado.
- A API é publicada por Nginx com HTTPS/TLS, encaminhando para o container
  Laravel. O Bearer usa Google OIDC, separado do OAuth do Gmail, com validação
  server-side de issuer, audience, claims temporais, assinatura, discovery/JWKS,
  rotação de chaves e allowlist de identidades autorizadas.

Durante o bootstrap, o implementador selecionará e fixará versões compatíveis
de PHP/Laravel, os pacotes concretos dos clientes Google e OAuth/OIDC e o
cliente HTTP do OpenRouter, após validar a documentação oficial. Não haverá
seleção automática dessas dependências em runtime. O Nginx já está decidido; o
registro final do cliente/audience OIDC e a allowlist ainda dependem da
operação.

`PD-009` e `PD-010` foram permanentemente dispensadas como blockers de
bootstrap. Essa dispensa não significa que testes ou checks passaram e não cria
o `validate_checks.py`, que continua ausente. Credenciais OAuth, cliente OIDC,
domínio/DNS/TLS, credenciais OpenRouter e segredos, armazenamento e backups da
VPS são pré-requisitos operacionais, não devem ser registrados no repositório.

## Como funciona

```text
Gmail OAuth (no servidor, gmail.modify + gmail.labels)
  → Sincronização (carga inicial + historyId incremental)
  → Captura canônica (mensagens/threads imutáveis)
  → Enriquecimento determinístico
  → Busca local lexical (PostgreSQL full-text) + semântica (`pgvector`) de Exemplos opt-in
  → Gate de privacidade (DLP + prompt injection no payload completo)
  → Pré-análise Jev assíncrona (opt-in, somente sugestão, quando habilitado)
  → Propostas consultivas de classificação (regras e/ou LLM)
  → Revisão humana escolhe/confirma/corrige e persiste a decisão oficial
  → categorized | uncategorized | inconclusive | failed
  → Labels de Categoria no Gmail somente após decisão oficial revisada
  → Herança para mensagens / reclassificação sob comando
```

Cada Categoria ativa tem um Label personalizado do Gmail com o mesmo nome
visível. O serviço projeta a decisão oficial para cada Mensagem da Thread e para
mensagens futuras que herdem essa decisão; uma nova Mensagem pode tornar a
Thread `pending`, mas a última decisão oficial continua governando os Labels
herdados até ação explícita da Revisão humana, sem reclassificação automática.
Uma correção limitada a uma Mensagem afeta somente ela. `uncategorized` não
recebe Label de Categoria. Labels alheios ao serviço são preservados, assim
como o Label de sistema `INBOX`.

Correções humanas podem virar Exemplos versionados somente com opt-in por Categoria. Seus embeddings são gerados localmente e recuperados como contexto de inferência; isso não atualiza pesos nem executa fine-tuning. O runtime/modelo/dimensão e os parâmetros de fusão permanecem pendentes em `PD-011`.

- **Categorias**: você cria quantas quiser (`ecs:categories:create`), com nome, descrição, exemplos e instrução opcional. Classificadores e provedores só podem propor categorias existentes — nunca inventam nem persistem o resultado oficial.
- **Estados do sistema** (não são categorias): `pending`, `categorized`, `uncategorized`, `inconclusive`, `failed`.
- **Pré-análise**: Jev pode sugerir categorias para a Revisão, mas não escolhe nem persiste resultado oficial. A tarefa só é enfileirada para Threads novas ou pendentes sem resultado oficial anterior e com Jev habilitado; `provider=none` não cria job. Threads já classificadas não entram na pré-análise automática após novas mensagens.
- **Resultado oficial**: somente a Revisão humana escolhe, confirma ou corrige e persiste a decisão oficial. `ecs:classify` e provedores apenas produzem propostas consultivas; falha de provedor não substitui a decisão oficial nem elimina a Revisão manual.
- **Provedores de IA externos**: a integração real do Jev usa OpenRouter, mas provedores permanecem opt-in e desligados por padrão; todo conteúdo passa pelo Gate de privacidade. `flag` exige consentimento por Thread.
- **Busca de Exemplos**: PostgreSQL full-text e `pgvector` recuperam somente Exemplos de Revisões humanas habilitados na Categoria ativa; o Gate inspeciona a Thread junto com os Exemplos antes do envio ao Jev.

## Status

Em especificação — ver `docs/`:

| Documento | Conteúdo |
|---|---|
| `docs/proposal.md` | Escopo e limites da entrega |
| `docs/research.md` | Decisões (Gmail API vs IMAP, cursor, taxonomia) |
| `docs/design.md` | Contratos, modelo de dados, pipeline, config |
| `docs/security-envelope.md` | Ameaças e controles obrigatórios |
| `docs/adr/0002-labels-de-categoria-no-gmail.md` | Decisão e limites da sincronização de Labels de Categoria |
| `docs/specs/email-classification/spec.md` | Requisitos e cenários |
| `docs/tasks.md` | Plano de implementação (0/N) |
| `docs/smoke-tests.md` | Matriz de smoke tests |
| `.specs/STATE.md` | Decisões e handoff Spec Lean |
| `.specs/features/email-classification/plan.md` | Plano canônico e critérios EARS |
| `.specs/features/email-classification/checks.md` | Provas Pest e cobertura profile `standard` |

## Licença

Apache-2.0. E-mails reais, tokens, prompts, respostas de provedores e dados pessoais nunca devem entrar neste repositório.
