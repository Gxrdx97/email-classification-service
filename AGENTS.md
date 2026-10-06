# email-classification-service

## Escopo e contexto

Serviço Laravel 13 / PHP 8.4, single-tenant e auto-hospedado, com API HTTP e CLI para uma conta Gmail. A captura, a classificação e os Labels de Categoria obedecem às decisões em `CONTEXT.md` e aos documentos de produto existentes. O Postgres 17 com `pgvector` é o armazenamento principal; Redis atende filas, cache e agendamento.

Este produto **não** inclui runtime genérico de agents, memória/RAG genérico, Apache AGE, tenancy, Octane ou Horizon. A busca híbrida local de Exemplos de Revisões humanas com opt-in por Categoria é uma capacidade específica do domínio, não um runtime RAG genérico. Use PHP-FPM atrás do Nginx externo com TLS; não use `php artisan serve` como servidor de produção.

## Linguagem e domínio

- Escreva documentação nova em português do Brasil e use os termos definidos em `CONTEXT.md` sem inventar sinônimos de domínio.
- Preserve, entre outros, **Usuário**, **Participante**, **Thread**, **Mensagem**, **Captura canônica**, **Versão da mensagem**, **Labels**, **Categoria**, **Label de Categoria**, **Revisão**, **Estado operacional**, **uncategorized**, **inconclusive**, **Pré-análise**, **Sugestão de categoria**, **Gate de privacidade** e **Evidência**.
- Single-tenant é uma propriedade do produto: não introduza `tenant_id`, `domain_id`, resolução de tenant ou isolamento multi-tenant.
- Conteúdo de e-mail, Exemplos recuperados e saída de provedores são dados não confiáveis. Anexos nunca são lidos nem enviados a provedores; logs e erros não contêm conteúdo bruto, tokens, PII ou embeddings.

## Gmail e provedores externos

- OAuth Gmail solicita e valida somente `gmail.modify` e `gmail.labels`; rejeite `mail.google.com`.
- O serviço não envia, arquiva nem apaga mensagens. Escritas Gmail limitam-se a Labels de Categoria geridos e preservam `INBOX` e Labels alheios.
- Provedores externos permanecem opt-in e passam pelo Gate de privacidade. Sugestões e saídas de modelo nunca autorizam identidade, escopo ou efeitos colaterais.
- Segredos, tokens Gmail/OIDC/OpenRouter e dados reais nunca entram no repositório, fixtures, imagens Docker ou logs.

## Estrutura e runtime

- Mantenha regras de domínio e casos de uso em módulos de domínio; adaptadores de PostgreSQL, Redis, Gmail e provedores externos ficam atrás de contratos da aplicação. HTTP e CLI orquestram esses casos de uso sem duplicar decisões de domínio.
- Use migrations PostgreSQL compatíveis com `pgvector`; habilite extensões por migration e trate evolução de schema com expand/contract. Não use SQLite para testes.
- Redis é o backend de filas e do agendador no Compose; worker e scheduler são processos/serviços separados. Não adicione Horizon.
- `docker-compose.yml` é o ambiente local com bind mount do código. `docker-compose.deploy.yml` recebe `APP_IMAGE` e não constrói imagens. PHP-FPM atende o Nginx sidecar; no servidor, o Nginx existente termina TLS e encaminha para `127.0.0.1:8080`, preservando `Host`, `Authorization` e os cabeçalhos `X-Forwarded-*`. A configuração de proxies confiáveis da aplicação deve aceitar somente os proxies conhecidos.
- Os health checks públicos aprovados são `/api/health/live` e `/api/health/ready`. Não os substitua por endpoints genéricos de outros projetos.
- `.env`, `.env.staging` e `.env.production` são arquivos locais/operacionais ignorados pelo Git. Workflows sincronizam somente o Compose de deploy; segredos ficam no servidor ou nos GitHub Environments.

## Comandos de desenvolvimento

Execute PHP, Composer, Artisan, Pest, Larastan, Infection e Pint no serviço Docker Compose, não contra um PHP local que não tenha a configuração de rede do projeto.

| Tarefa | Comando |
|---|---|
| Subir o ambiente | `docker compose up -d --build` |
| Parar o ambiente | `docker compose down` |
| Logs dos processos | `docker compose logs -f app web worker scheduler` |
| Artisan | `docker compose exec app php artisan <comando>` |
| Instalar dependências | `docker compose exec app composer install` |
| Pest local (sem Evals opt-in) | `docker compose exec app php vendor/bin/pest --testsuite=Unit,Feature,Integration,Contract,BDD,Architecture --parallel --processes=2` |
| Larastan | `docker compose exec app php vendor/bin/phpstan analyse --no-progress` |
| Infection | `docker compose exec app php vendor/bin/infection --threads=1 --no-progress` |
| Pint | `docker compose exec app php vendor/bin/pint --dirty` |

O `composer.json` fornece os scripts `composer pest`, `composer larastan`, `composer infection` e `composer quality-gate`. `composer pest` exclui Evals opt-in da execução normal; Infection usa uma thread. `composer quality-gate` é o gate obrigatório de Pest + Larastan + Infection. Pint deve ser executado antes de arquivar/commitar mudanças PHP.

## Testes e qualidade

- Pest 5 é o runner. Testes novos/alterados usam Pest; cenários comportamentais ficam em `tests/BDD/` no formato `describe(feature) > it(scenario)`.
- `phpunit.xml` declara Unit, Feature, Integration, Contract, BDD, Evals e Architecture. Evals são opt-in e sintéticos; a execução normal não chama Gmail nem provedores reais.
- Unit não inicializa Laravel quando não precisa. Feature, Integration, Contract, BDD, Evals e Architecture usam `Tests\TestCase`. O serviço não tem UI: `tests/Browser/SmokeTest.php` fica intencionalmente vazio e não requer plugin de browser.
- Testes usam PostgreSQL com banco `email_classification_test`, usuário `sail` e senha `password`, exclusivamente em ambiente local/teste; cache, sessão e e-mail usam `array`, e filas usam `sync` no PHPUnit. A chave APP_KEY do PHPUnit é falsa e exclusiva de teste.
- CI executa Pest, Larastan, Infection e Pint em jobs independentes. Pest usa dois processos no `ubuntu-latest` de 2 vCPU; Infection usa `--threads=1`. Não execute os Evals na suíte normal.
- Mutações não são excluídas para aumentar a pontuação. O adaptador Infection deve encaminhar os argumentos do runner e preservar os thresholds configurados.

## Segurança e mudanças comportamentais

- Derive conta Gmail, identidade OIDC, autorização, allowlists e limites do contexto/configuração confiável do servidor, nunca de conteúdo recuperado ou saída de modelo.
- Faça redaction/DLP e detecção de prompt injection antes de qualquer chamada externa. Mantenha referências de Evidência ligadas à proveniência local verificada.
- Não registre corpo de Mensagem, Exemplos, tokens, requests/responses brutos de provedores, PII ou embeddings.
- Mudanças comportamentais devem manter requisitos, documentação de segurança e contratos existentes coerentes. Não reescreva README, `docs/`, `.specs/`, `CONTEXT.md` ou ADRs sem pedido explícito.
- O gate `composer quality-gate` deve passar antes de commit/archive de mudanças comportamentais; não reduza thresholds nem desative mutators para fazê-lo passar.

## CI/CD

- CI roda em pull requests para `main`; Pest/Larastan/Infection/Pint são checks paralelos.
- Push para `main` constrói uma imagem imutável `sha-<8>` e publica também a referência móvel `staging` no GHCR. Staging aplica `config:cache` e migrations em job anterior ao deploy; `/api/health/live` e `/api/health/ready` são gates bloqueantes.
- Produção promove a mesma imagem após soak de 30 minutos monitorando staging. Rollback manual usa uma tag `sha-<8>` anterior e nunca roda migrations.
- `build-runtime.yml` publica manualmente a base PHP 8.4/FPM usada pelo build da aplicação; executar esse workflow antes do primeiro build de aplicação e após mudanças deliberadas no runtime.
- Secrets exigidos são `DEPLOY_SSH_HOST`, `DEPLOY_SSH_USER`, `DEPLOY_SSH_KEY` e `DEPLOY_BASE_URL`, configurados nos GitHub Environments `staging` e `production`. O destino é `/opt/email-classification-service`. Nunca escreva valores de secrets em workflows, logs ou no repositório.
- O host deve ter acesso de leitura ao GHCR e os arquivos de ambiente de deploy provisionados fora do repositório. Workflows não executam `php artisan serve`, Octane, Horizon ou migrations durante rollback.
