<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

final class DlpScanner
{
    public const MAX_NESTING_DEPTH = 32;

    public const MAX_INSPECTED_COMPONENTS = 10_000;

    public const MAX_INSPECTED_BYTES = 4_194_304;

    public const MAX_STRING_BYTES = 1_048_576;

    /** @var array<string, string> */
    private const PATTERNS = [
        'email_address' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
        'credential' => '/\b(?:sk-[A-Za-z0-9_-]{12,}|sk_(?:live|test|proj)_[A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9]{20,}|glpat-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16}|ASIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{35})\b/iu',
        'bearer_token' => '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/iu',
        'password_value' => '/\b(?:password|passwd|pwd|api[_-]?key|secret|access[_-]?token|client[_-]?secret|refresh[_-]?token)\s*[:=]\s*[^\s,;]+/iu',
        'ssn' => '/\b[0-9]{3}-[0-9]{2}-[0-9]{4}\b/u',
        'phone_number' => '/(?<![\pL\pN])\+?(?:\d[\s().-]*){9,14}\d(?![\pL\pN])/u',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function findingCodes(array $payload): array
    {
        $findings = [];
        /** @var list<array{0: array<mixed>, 1: int}> $stack */
        $stack = [[$payload, 0]];
        $inspectedComponents = 0;
        $inspectedBytes = 0;

        while ($stack !== []) {
            [$values, $depth] = array_pop($stack);

            foreach ($values as $key => $value) {
                if ($inspectedComponents >= self::MAX_INSPECTED_COMPONENTS) {
                    $findings['unsupported_payload_value'] = true;

                    return array_keys($findings);
                }

                $inspectedComponents++;

                if (is_string($key)) {
                    $this->inspectString($key, $findings, $inspectedBytes);
                }

                if ($inspectedComponents >= self::MAX_INSPECTED_COMPONENTS) {
                    $findings['unsupported_payload_value'] = true;

                    return array_keys($findings);
                }

                $inspectedComponents++;

                if (is_string($value)) {
                    $this->inspectString($value, $findings, $inspectedBytes);
                } elseif (is_array($value)) {
                    if ($depth >= self::MAX_NESTING_DEPTH) {
                        $findings['unsupported_payload_value'] = true;

                        return array_keys($findings);
                    }

                    $stack[] = [$value, $depth + 1];
                } elseif (is_float($value) && ! is_finite($value)) {
                    $findings['unsupported_payload_value'] = true;
                } elseif (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_float($value)) {
                    $findings['unsupported_payload_value'] = true;
                }
            }
        }

        return array_keys($findings);
    }

    /**
     * @param  array<string, bool>  $findings
     */
    private function inspectString(string $value, array &$findings, int &$inspectedBytes): void
    {
        $valueBytes = strlen($value);

        if (
            $valueBytes > self::MAX_STRING_BYTES
            || $inspectedBytes > self::MAX_INSPECTED_BYTES - $valueBytes
            || preg_match('//u', $value) !== 1
        ) {
            $findings['unsupported_payload_value'] = true;

            return;
        }

        $inspectedBytes += $valueBytes;
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        if (! is_string($normalized)) {
            $findings['unsupported_payload_value'] = true;

            return;
        }

        $normalized = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');

        foreach (self::PATTERNS as $code => $pattern) {
            $matched = preg_match($pattern, $normalized);

            if ($matched === false) {
                $findings['unsupported_payload_value'] = true;

                return;
            }

            if ($matched === 1) {
                $findings[$code] = true;
            }
        }
    }
}
