# Estado do projeto

## Decisões

| ID | Decisão | Justificativa | Estado | Data |
| --- | --- | --- | --- | --- |
| AD-001 | A thread é a unidade de classificação e a fonte de verdade dos resultados herdados pelas mensagens | O ADR aceito sobre thread impede resultados divergentes por mensagem e preserva o contexto das respostas | active | 2026-09-20 |
| AD-002 | Laravel é o framework da aplicação, PHP é o runtime e Pest é o executor obrigatório de testes | O Usuário escolheu Laravel e Pest; o implementador deve selecionar e fixar versões compatíveis durante o bootstrap, após validar a documentação oficial | active | 2026-09-24 |
| AD-003 | Jev é um provedor de pré-análise opcional e não autoritativo antes da Revisão humana | Sugestões reduzem o esforço de Revisão sem permitir que uma saída externa não confiável altere o Estado operacional | active | 2026-09-22 |
| AD-004 | A pré-análise automática executa de forma assíncrona após o sync somente para threads novas ou pendentes sem resultado oficial anterior, e somente quando Jev está habilitado | Uma thread classificada não pode ser reclassificada implicitamente quando uma nova mensagem chega; `provider=none` não cria job de pré-análise | active | 2026-09-22 |
| AD-005 | Um `flag` de privacidade exige consentimento explícito para a thread individual antes do envio externo | O opt-in global sozinho é insuficiente para uma thread sinalizada por conteúdo sensível ou prompt injection | active | 2026-09-22 |
| AD-006 | A entrega é completa e inclui núcleo, CLI `ecs:*` e HTTP autenticado; PostgreSQL, Redis e Docker são a infraestrutura escolhida | O Usuário confirmou a implementação completa e a execução auto-hospedada com persistência relacional, fila Redis e containers | active | 2026-09-24 |
| AD-007 | O Gate de privacidade executa antes de qualquer provedor externo e usa `abort` como ação padrão para dado sensível ou prompt injection | A Thread não é enviada quando o conteúdo é bloqueado; somente um evento sanitizado é registrado | active | 2026-09-24 |
| AD-008 | Jev é acessado via OpenRouter e `OPENROUTER_MODEL` é obrigatório; nenhum modelo é escolhido automaticamente | A identidade do modelo deve ser explícita e reproduzível em cada Pré-análise | active | 2026-09-24 |
| AD-009 | A API roda na VPS atrás de Nginx com HTTPS/TLS e usa Bearer JWT/OIDC do Google, distinto do OAuth do Gmail | O serviço não é restrito a localhost; a validação server-side deve cobrir issuer, audience, claims temporais, assinatura, descoberta/JWKS e rotação de chaves | active | 2026-09-24 |
| AD-010 | O implementador seleciona e fixa as versões e os pacotes concretos durante o bootstrap, após validar compatibilidade na documentação oficial | A escolha técnica foi delegada pelo Usuário; runtime e dependências não serão selecionados automaticamente em produção | active | 2026-09-25 |
| AD-011 | Nginx é o reverse proxy da VPS e termina HTTPS/TLS | O Usuário escolheu Nginx; Caddy não faz parte do baseline de implantação | active | 2026-09-25 |
| AD-012 | Os adaptadores reais de Gmail e OpenRouter são preparados desde o bootstrap, mas chamadas reais exigem configuração explícita e opt-in | Segredos ficam fora do repositório; a suíte Pest normal usa fakes e fixtures sintéticas, enquanto smoke/evals reais são opt-in | active | 2026-09-25 |
| AD-013 | A pré-análise pode recuperar Exemplos de Revisões humanas corrigidas e explicitamente habilitadas por Categoria, usando busca lexical no PostgreSQL e busca semântica com `pgvector`; os embeddings são gerados localmente e não há fine-tuning nesta etapa | Os Exemplos aprovados são contexto recuperado em inferência, não treinamento nem autoridade; limita a fonte de contexto e evita envio externo para gerar embeddings | active | 2026-09-26 |

| AD-014 | O serviço projeta apenas resultados oficiais revisados como Labels de Categoria do Gmail, com OAuth `gmail.modify` + `gmail.labels` e allowlist server-side de IDs/operações; a Thread determina a projeção herdada, exceto por correção oficial restrita à Message | Organiza mensagens no Gmail sem tornar Labels fonte de verdade, preserva `INBOX` e Labels alheios, não envia/arquiva/apaga mensagens e suporta reconciliação idempotente | active | 2026-10-03 |
| AD-015 | Somente uma Revisão humana escolhe e persiste o resultado oficial; Jev e quaisquer provedores de modelo produzem recomendações consultivas | O Usuário confirmou explicitamente que a decisão oficial pertence à Revisão humana; uma saída automática não pode gravar ou substituir classificação oficial | active | 2026-10-05 |
| AD-016 | A chegada de Message nova pode tornar a Thread operacionalmente `pending` sem apagar a última Classificação oficial; Labels herdadas continuam derivadas dessa última decisão até nova ação explícita | Mantém o trabalho pendente de revisão separado da última decisão oficial e impede reclassificação automática ou remoção indevida de Labels | active | 2026-10-05 |
| AD-017 | PD-009 e PD-010 estão permanentemente dispensadas como bloqueios por autorização explícita do Usuário; isso não substitui execução nem aprovação dos testes de qualidade | O Usuário liberou bootstrap e validade estrutural dos checks sem exigir a atividade preparatória ou o validator citado | active | 2026-10-05 |

## Handoff

- **Atualização**: a implementação Laravel/PHP, as migrations e a suíte Pest existem. Em 2026-10-08, `composer quality-gate` passou: Pest (178 testes, 944 assertions), Larastan (sem erros) e Infection (3.607 mutantes mortos; Mutation Code Coverage e Covered Code MSI de 100%). `PD-009` e `PD-010` continuam dispensadas permanentemente; as decisões oficiais continuam humanas.

**Feature**: `email-classification`
**Onde**: os artefatos Spec Lean e a documentação legada registram o escopo completo e as decisões de runtime, privacidade, integrações e API
**Em andamento**: código de domínio, adaptadores e testes já estão presentes. O gate agregado passou, mas C1–C62 ainda não têm evidência individual mapeada; alguns selectors de prova declarados não correspondem a testes encontrados na suíte. `PD-011` permanece aberto para decisão/avaliação de embeddings e recuperação.
**Próximo passo**: reconciliar cada C1–C62 com um teste executável, corrigir selectors obsoletos e executar/provar os critérios que ainda faltam. Só então registrar critérios individuais como `green`.
**Bloqueios**: nenhum bloqueio de bootstrap por PD-009/PD-010; o registro OIDC, as credenciais de operação e as políticas de go-live continuam pré-condições para dados reais/implantação.
**Alterações locais não commitadas nesta sessão**: `scripts/infection-pest.php`, `tests/Unit/Tooling/InfectionPestAdapterTest.php` e os registros de progresso atualizados
**Branch**: branch de trabalho atual

## Pendências registradas

| ID | Pendência | Tipo | Estado |
| --- | --- | --- | --- |
| PD-001 | Selecionar e fixar versões compatíveis de PHP e Laravel durante o bootstrap, após validação da documentação oficial | implementação | resolved |
| PD-002 | Selecionar e fixar o cliente Google Gmail/OAuth e as bibliotecas concretas para OAuth/OIDC durante o bootstrap | implementação | resolved |
| PD-003 | Selecionar e fixar o cliente HTTP concreto para OpenRouter durante o bootstrap | implementação | resolved |
| PD-004 | Escolha do reverse proxy da VPS: Nginx | implantação | resolved |
| PD-005 | Registro do cliente/audience Google OIDC e allowlist de identidades autorizadas | implantação e segurança | pending |
| PD-006 | Termos, residência, retenção e subprocessadores dos provedores externos | go-live e privacidade | pending |
| PD-007 | Credenciais OAuth Gmail, cliente OIDC, domínio/DNS/TLS, credenciais OpenRouter e segredos do VPS | operação | pending |
| PD-008 | Política de armazenamento, backups e recuperação do VPS | operação e segurança | pending |
| PD-009 | Atividade preparatória anterior ao bootstrap dispensada permanentemente por autorização explícita do Usuário | sequenciamento | waived |
| PD-010 | Validator `validate_checks.py` dispensado permanentemente como bloqueio por autorização explícita do Usuário; a execução real dos testes continua obrigatória para declarar gates aprovados | tooling e qualidade | waived |
| PD-011 | Selecionar e validar runtime/modelo local de embeddings e dimensão; definir fusão lexical-semântica, top-k e comportamento limitado quando um canal falhar | implementação e avaliação | pending |
| PD-012 | Revisão humana é a única fonte do resultado oficial; Jev/LLM são consultivos e não podem aplicar sugestões automaticamente | política de autoridade | resolved |

PD-001, PD-002 e PD-003 foram resolvidas pelo manifesto/lockfile. PD-005 a
PD-008 continuam pré-condições de implantação, operação, segurança ou go-live.
PD-009 e PD-010 foram dispensadas permanentemente pelo Usuário e não bloqueiam
bootstrap nem a validade estrutural dos checks; nenhuma dispensa afirma que
testes foram executados ou aprovados. `PD-011` continua sendo trabalho de
implementação/avaliação da busca híbrida, preservando embeddings locais e a
fonte restrita a Exemplos opt-in de Revisões. `PD-012` foi resolvida: somente
uma Revisão humana grava o resultado oficial.
