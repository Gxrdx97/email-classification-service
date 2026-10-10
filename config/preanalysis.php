<?php

return [
    // Captura só cria intenções duráveis; chamadas ao provider pertencem ao consumer pós-commit.
    'provider' => env('PREANALYSIS_PROVIDER', 'none'),
    'enabled' => (bool) env('PREANALYSIS_ENABLED', false),
    'model' => env('PREANALYSIS_MODEL'),
    // Versões explícitas e estáveis do contrato de Pré-análise; mudanças devem ser deliberadas.
    'question_version' => env('PREANALYSIS_QUESTION_VERSION', 'question-v1'),
    'schema_version' => env('PREANALYSIS_SCHEMA_VERSION', 'schema-v1'),
    'privacy_policy_version' => env('PREANALYSIS_PRIVACY_POLICY_VERSION', 'v1'),
];
