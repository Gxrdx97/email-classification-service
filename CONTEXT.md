# email-classification-service

Contexto de domínio do serviço de classificação de e-mails de uma conta Gmail pessoal. Single-tenant e self-hosted. O serviço captura mensagens e sincroniza com o Gmail somente os Labels de Categoria definidos por classificações oficiais revisadas; não envia, arquiva nem apaga mensagens.

## Idioma

### Pessoas e papéis

**Usuário**:
A única pessoa atendida pelo serviço: hospeda o serviço, administra categorias, revisa resultados e configura privacidade.
_Avoid_: operador, admin, dono da conta

**Participante**:
Pessoa (endereço de e-mail) que envia ou recebe uma mensagem — remetente ou destinatário, normalizado.
_Avoid_: usuário de e-mail, contact

### Captura e sincronização

**Captura canônica**:
Armazenamento imutável de mensagens e threads como chegaram do Gmail, dedupe por provider message id.
_Avoid_: cache, espelho

**Versão da mensagem**:
Instância imutável do conteúdo de uma mensagem, identificada por `content_hash`. Mudança de conteúdo no Gmail cria uma nova versão; a anterior permanece.
_Avoid_: revisão, edição da mensagem

**Labels**:
Rótulos do Gmail, incluindo os recebidos na captura e os Labels de Categoria geridos pelo serviço. São metadado vivo: mudanças de Labels atualizam o metadado, nunca criam nova versão da mensagem. Labels alheios à gestão do serviço permanecem fora de seu controle.
_Avoid_: tag de categoria

**Anexo**:
Metadados de um anexo da mensagem (nome, tipo, tamanho). O conteúdo de anexo nunca é lido, armazenado nem enviado a provedores.
_Avoid_: arquivo

**Cursor**:
`history_id` do Gmail, único por conta; avança somente após persistência confirmada. Expirado ⇒ resync completo idempotente.
_Avoid_: offset, checkpoint

### Classificação

**Thread**:
Cadeia de mensagens correlacionadas por `thread_id` do Gmail (e `Message-ID`/`In-Reply-To`/`References`). Unidade de classificação: a thread inteira.
_Avoid_: conversa, tópico

**Herança**:
Mensagem individual herda o estado operacional e as categorias da thread classificada — a thread é a fonte de verdade. Uma correção de revisão aplicada apenas à mensagem cria divergência registrada em relação à thread.
_Avoid_: espelho, espelhamento, cópia

**Estado operacional**:
Namespace reservado do sistema: `pending | categorized | uncategorized | inconclusive | failed`. Nunca é uma categoria.
_Avoid_: status, estado da categoria

**uncategorized**:
Resultado em que o classificador afirma explicitamente que nenhuma categoria se aplica, com confiança ≥ limiar.
_Avoid_: sem categoria, pendente de categoria

**inconclusive**:
Abstenção: o classificador não consegue afirmar nada — nem pertencimento, nem o vazio. Sempre com `inconclusive_reason`.
_Avoid_: dúvida, inconclusivo genérico

**conflict**:
Valor de `inconclusive_reason` para sobreposição estrutural entre definições de categorias que competem pela mesma Thread.
_Avoid_: colisão, disputa

**Confiança (confidence)**:
Número 0..1 no resultado. Provedor de regras deriva score determinístico dos padrões casados; LLM se auto-avalia. Calibração com dados de revisão é melhora futura.
_Avoid_: score genérico, certeza

**Categoria**:
Definição versionada criada pelo usuário (nome, slug, descrição, exemplos opcionais, instrução opcional). O classificador só pode atribuir categorias existentes — nunca inventa.
_Avoid_: tag, Label, rótulo

**Label de Categoria**:
Label personalizado do Gmail associado a uma Categoria pelo mesmo nome visível. Reflete somente uma atribuição oficial revisada: a Thread classificada determina os Labels herdados pelas mensagens; uma correção restrita a uma mensagem determina somente os Labels daquela mensagem. Não é fonte da verdade da classificação.
_Avoid_: categoria do Gmail

**Exemplo**:
Fragmento de texto associado à definição de uma categoria, como referência de classificação. Correções de revisão podem virar exemplos quando a categoria tem o recurso habilitado (opt-in por categoria), criando nova versão da definição. Embeddings gerados localmente e índices lexicais/semânticos PostgreSQL podem permitir a recuperação desses exemplos como contexto de inferência; isso não atualiza pesos nem executa fine-tuning.
_Avoid_: few-shot, amostra

**Revisão**:
Correção feita pelo usuário a um resultado, registrada com proveniência humana. Por correção, o usuário escolhe aplicar à thread ou restringir à mensagem individual.
_Avoid_: feedback, ajuste manual

**Provedor de classificação**:
Adaptador que produz o resultado: regras determinísticas ou LLM externo (opt-in, desabilitado por padrão).
_Avoid_: adapter, engine

**Reclassificação**:
Aplicação da versão atual das definições a threads já classificadas, sob comando do usuário, preservando o histórico dos resultados anteriores. Mensagens novas numa thread não disparam reclassificação automática.
_Avoid_: recalcular, resync

**Pré-análise**:
Análise preliminar não autoritativa de uma thread antes da Revisão, produzindo sugestões de categoria sem alterar o Estado operacional.
_Avoid_: classificação automática, decisão do provedor

**Sugestão de categoria**:
Candidata versionada produzida pela Pré-análise, com probabilidade e incerteza; não é atribuição oficial até a confirmação do Usuário.
_Avoid_: categoria atribuída, resultado final

**Provedor de pré-análise**:
Adaptador que produz Sugestões de categoria estruturadas para orientar a Revisão. Jev é um provedor externo opcional; sua saída é dado não confiável.
_Avoid_: classificador final, autoridade

### Privacidade

**Gate de privacidade**:
DLP + detecção de prompt injection aplicados obrigatoriamente antes de qualquer envio a provedor externo; política `redact | flag | abort` por configuração.
_Avoid_: filtro, sanitização

**Evidência**:
Referências que sustentam o resultado (`evidence_refs[]`), incluindo metadados de anexos (nome, tipo, tamanho) — nunca conteúdo de anexo.
_Avoid_: proof, debug info
