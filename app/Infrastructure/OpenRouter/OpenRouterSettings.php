<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenRouter;

use InvalidArgumentException;

final readonly class OpenRouterSettings
{
    private function __construct(
        public bool $enabled,
        public string $baseUrl,
        public ?string $apiKey,
        public ?string $model,
        public int $timeoutSeconds,
    ) {}

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $baseUrl = rtrim((string) ($settings['base_url'] ?? 'https://openrouter.ai/api/v1'), '/');
        $timeoutSeconds = filter_var($settings['timeout'] ?? 30, FILTER_VALIDATE_INT);
        $apiKey = is_string($settings['api_key'] ?? null) ? $settings['api_key'] : null;
        $model = is_string($settings['model'] ?? null) ? trim($settings['model']) : null;

        $url = parse_url($baseUrl);

        if ($baseUrl === ''
            || ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ($url['host'] ?? null) !== 'openrouter.ai'
            || ($url['path'] ?? null) !== '/api/v1'
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
            || isset($url['fragment'])) {
            throw new InvalidArgumentException('OpenRouter must use the HTTPS OpenRouter API endpoint.');
        }

        if ($timeoutSeconds === false || $timeoutSeconds < 1 || $timeoutSeconds > 45) {
            throw new InvalidArgumentException('OpenRouter timeout must be between 1 and 45 seconds.');
        }

        if ($enabled && (trim((string) $apiKey) === '' || $model === null || $model === '')) {
            throw new InvalidArgumentException('Enabled OpenRouter requires an API key and a fixed model.');
        }

        if ($apiKey !== null && preg_match('/[\r\n\x00]/', $apiKey) === 1) {
            throw new InvalidArgumentException('OpenRouter API key is invalid.');
        }

        if ($model !== null
            && (strlen($model) > 256
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\/[A-Za-z0-9][A-Za-z0-9._:-]*$/', $model) !== 1
                || strtolower($model) === 'openrouter/auto')) {
            throw new InvalidArgumentException('OpenRouter model identifier is invalid.');
        }

        return new self($enabled, $baseUrl, $apiKey, $model, $timeoutSeconds);
    }

    public function completionsUrl(): string
    {
        return $this->baseUrl.'/chat/completions';
    }
}
