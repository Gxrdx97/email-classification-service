# Glossário de CI/CD

Este glossário documenta os workflows desta aplicação single-tenant. O Nginx do host termina TLS e encaminha HTTP para o serviço `web`, publicado somente em `127.0.0.1:8080`. O proxy preserva `Host`, `Authorization` e `X-Forwarded-*`; a aplicação confia apenas nos proxies configurados. Logs do proxy não devem guardar query strings, corpo de requisição, tokens, IPs ou outros dados pessoais.

## Build da aplicação

Push em `main` constrói uma imagem da aplicação e publica no GHCR como `sha-<8>` e `staging`. A tag `sha-<8>` identifica a imagem imutável promovida; `staging` é apenas uma referência móvel. Os jobs de promoção não recompilam a aplicação.

O owner do caminho GHCR é obtido em runtime de `${{ github.repository_owner }}`. O slug é `email-classification-service`. O host precisa de acesso de leitura ao GHCR; autenticação de pull, quando necessária, é provisionada no host e não é transmitida nem registrada pelos workflows.

## Runtime PHP

`build-runtime.yml` é manual e publica o target PHP 8.4/FPM com extensões, Nginx e configuração HTTP usados pelo Dockerfile da aplicação. Executá-lo antes do primeiro build da aplicação e após uma atualização deliberada do runtime. A aplicação não usa `php artisan serve`, Octane ou Horizon.

## Deploy de staging

Executado em push para `main`. O workflow sincroniza apenas `docker-compose.deploy.yml`, inicia Postgres/Redis, executa `config:cache` e migrations em job separado e, somente depois, sobe a imagem. O cache de configuração do job de release é descartável; cada container Laravel cria seu cache para a própria versão no startup, sem compartilhá-lo entre versões. Não copia arquivos `.env` para o VPS. O ambiente usa `.env.staging` provisionado fora do repositório.

## Health gates

Depois do deploy, ambos os endpoints precisam responder HTTP 200:

- `/api/health/live`
- `/api/health/ready`

O primeiro verifica liveness; o segundo depende da prontidão operacional. Falha ou timeout bloqueia a etapa seguinte.

## Soak

Após staging ficar saudável, produção espera 30 minutos monitorando os dois endpoints. Qualquer resposta diferente de HTTP 200 cancela a promoção.

## Promoção

O evento automático `workflow_run` promove o `sha-<8>` do deploy de staging bem-sucedido. `workflow_dispatch` permite selecionar opcionalmente uma tag `sha-<8>` que tenha um run de staging bem-sucedido; sem seleção, usa o run de staging bem-sucedido mais recente. O soak continua obrigatório. Produção usa `.env.production` provisionado fora do repositório e aplica suas migrations antes de subir a mesma imagem.

## Rollback

`rollback.yml` é manual e exige uma tag de imagem anterior `sha-<8>` e ambiente `staging` ou `production`. Reaplica a imagem sem migrations e passa novamente pelos health gates.

## GitHub Environments e segredos

Configure os environments `staging` e `production` com os nomes de secrets abaixo; os valores pertencem ao GitHub e nunca aos arquivos do projeto:

- `DEPLOY_SSH_HOST`
- `DEPLOY_SSH_USER`
- `DEPLOY_SSH_KEY`
- `DEPLOY_BASE_URL`

Os workflows usam `/opt/email-classification-service` como diretório remoto e não imprimem os valores dos secrets. `GITHUB_TOKEN` é o token temporário fornecido pelo GitHub Actions para publicar imagens.
