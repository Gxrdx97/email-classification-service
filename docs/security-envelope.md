# Security Envelope — email-classification-service

## Ameaças

1. **Token/conta comprometida** — refresh token vazado via logs, dumps ou repositório.
2. **Escopo excessivo** — pedir `gmail.modify`/`mail.google.com` sem necessidade; escrita acidental no Gmail.
3. **Envio indevido a provedor externo** — PII/segredos/injeção indo para LLM sem consentimento do operador.
4. **Prompt injection** — conteúdo do e-mail comandando o classificador/provedor.
5. **Vazamento de dados reais no projeto** — e-mails, tokens ou fixtures pessoais commitados.
6. **Estado residido indefinidamente** — corpos brutos retidos para sempre sem política.
7. **Duplicação/perda no sync** — reprocesso idempotente quebrado; cursor avançado antes da captura persistida.
8. **Cross-request leak** — estado da conta/modelo em singleton/static (se executado sob servidor long-lived).
9. **Classificador inventando categorias** — IDs fora da allowlist corrompendo dados.
10. **Replay/errata do OAuth** — state reutilizado, redirect aberto, callback spoofado.

## Controles (obrigatórios)

- **OAuth**: escopo fixo `gmail.readonly` validado na resposta; state server-side one-time; redirect URI fixo; refresh token cifrado em repouso; acesso via env apenas; revogação suportada.
- **Somente leitura**: nenhuma chamada de escrita ao Gmail em todo o código (lint/revisão; não existe client de envio).
- **Cursor de sync**: `history_id` só avança **após** persistência confirmada dos itens do lote; falha tipada + backoff; resync completo quando histórico expira.
- **Idempotência**: unique `(account, provider_message_id)`; hash de conteúdo; dedupe de classifications por `(message, categories_version, classifier)`.
- **Gate de privacidade**: antes de qualquer chamada externa → DLP (regex PII/segredos) + prompt injection detector; ação por política (`redact|flag|abort`); provider desabilitado por padrão.
- **Allowlist de categorias**: saída do classificador validada contra IDs ativos; ID desconhecido → descartado e logado como `reason_code` (nunca persistido).
- **Estados reservados**: `pending|categorized|uncategorized|inconclusive|failed` não são categorias; impossível criar categoria com esses slugs.
- **Dados reais**: proibidos no repositório; fixtures sintéticas apenas; logs sanitizados (sem corpo, token, PII).
- **Retenção**: retention_days configurável para corpo bruto; job de sweep; comando de wipe total.
- **Stateless/scoped**: serviços com estado por request registrados scoped; nada de static request-scoped (aplica-se se hospedado em servidor persistente).
- **Segredos**: somente env/secret store; `.env` no `.gitignore`; nada de chave em spec/fixture/commit.

## Gates de habilitação

- Provedor externo de LLM: só após operador configurar explicitamente + política de privacidade definida.
- Sync real (Gmail): só com credenciais OAuth do operador presentes; caso contrário apenas fake/sintético.

## Perguntas em aberto (bloqueiam produção)

- Verificação do app no Google (consent screen, modo testing vs produção) e responsabilidade do operador.
- Residência/dados enviados ao provedor escolhido (termos do operador).
- Política exata de redaction padrão (quais padrões de PII por default).
- Se HTTP local será habilitado por default ou apenas CLI no MVP.
