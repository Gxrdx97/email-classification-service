<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

final class PromptInjectionDetector
{
    /** @var list<string> */
    private const PATTERNS = [
        '/\b(?:ignore|disregard|forget|override|bypass|skip)\s+(?:(?:all|every|the)\s+)?(?:previous|prior|above|earlier|system|developer)\s+(?:instructions?|prompts?|rules?)\b/iu',
        '/\b(?:reveal|print|show|expose|disclose|dump|repeat|return|provide)\s+(?:(?:all|every|each|other|previous|prior)\s+)*(?:messages?|emails?|threads?|conversations?|prompts?|instructions?|content)\b/iu',
        '/\b(?:reveal|print|show|expose|disclose|dump)\s+(?:the\s+)?(?:system|developer)\s+(?:prompt|instructions?)\b/iu',
        '/\b(?:you are now|act as|roleplay as)\b/iu',
        '/\b(?:do not follow|stop following|ignore)\s+(?:the\s+)?(?:system|developer|user)\s+(?:instructions?|prompt)\b/iu',
        '/<\|(?:system|assistant|developer)\|>/iu',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function detected(array $payload): bool
    {
        /** @var list<array{0: array<mixed>, 1: int}> $stack */
        $stack = [[$payload, 0]];
        $inspectedComponents = 0;
        $inspectedBytes = 0;

        while ($stack !== []) {
            [$values, $depth] = array_pop($stack);

            foreach ($values as $key => $value) {
                if ($inspectedComponents >= DlpScanner::MAX_INSPECTED_COMPONENTS) {
                    return true;
                }

                $inspectedComponents++;

                if (is_string($key) && $this->matches($key, $inspectedBytes)) {
                    return true;
                }

                if ($inspectedComponents >= DlpScanner::MAX_INSPECTED_COMPONENTS) {
                    return true;
                }

                $inspectedComponents++;

                if (is_string($value)) {
                    if ($this->matches($value, $inspectedBytes)) {
                        return true;
                    }
                } elseif (is_array($value)) {
                    if ($depth >= DlpScanner::MAX_NESTING_DEPTH) {
                        return true;
                    }

                    $stack[] = [$value, $depth + 1];
                } elseif (is_float($value) && ! is_finite($value)) {
                    return true;
                } elseif (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_float($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize only for inspection; the original payload is never mutated or logged.
     */
    private function matches(string $value, int &$inspectedBytes): bool
    {
        $valueBytes = strlen($value);

        if (
            $valueBytes > DlpScanner::MAX_STRING_BYTES
            || $inspectedBytes > DlpScanner::MAX_INSPECTED_BYTES - $valueBytes
            || preg_match('//u', $value) !== 1
        ) {
            return true;
        }

        $inspectedBytes += $valueBytes;
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        if (! is_string($normalized)) {
            return true;
        }

        $normalized = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');

        foreach (self::PATTERNS as $pattern) {
            $matched = preg_match($pattern, $normalized);

            if ($matched === false || $matched === 1) {
                return true;
            }
        }

        return false;
    }
}
