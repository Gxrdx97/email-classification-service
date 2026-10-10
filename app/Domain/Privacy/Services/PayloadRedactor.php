<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

final class PayloadRedactor
{
    /** @var list<string> */
    private const INJECTION_PATTERNS = [
        '/\b(?:ignore|disregard|override)\s+(?:all\s+)?(?:previous|prior|above|system)\s+(?:instructions?|prompts?)\b/i',
        '/\b(?:reveal|print|show|expose)\s+(?:the\s+)?(?:system|developer)\s+(?:prompt|instructions?)\b/i',
        '/\b(?:you are now|act as|roleplay as)\b/i',
        '/\b(?:do not follow|stop following)\s+(?:the\s+)?(?:system|developer|user)\s+(?:instructions?|prompt)\b/i',
        '/<\|(?:system|assistant|developer)\|>/i',
    ];

    /** @var list<string> */
    private const SENSITIVE_PATTERNS = [
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
        '/\b(?:sk-[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})\b/',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
        '/\b(?:password|passwd|api[_-]?key|secret|access[_-]?token)\s*[:=]\s*[^\s,;]+/i',
        '/(?<![\pL\pN])\+?(?:\d[\s().-]*){9,14}\d(?![\pL\pN])/u',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = $this->redactString($value);
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    private function redactString(string $value): string
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            $value = preg_replace($pattern, '[PROMPT_INJECTION_REDACTED]', $value) ?? '[REDACTION_FAILED]';
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value) ?? '[REDACTION_FAILED]';
        }

        return $value;
    }
}
