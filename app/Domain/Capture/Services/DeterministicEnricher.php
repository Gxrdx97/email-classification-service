<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Data\CanonicalMessage;
use App\Domain\Capture\Data\EnrichedMessage;
use App\Domain\Capture\Data\Participant;
use InvalidArgumentException;

final class DeterministicEnricher
{
    public const EXTRACTOR_NAME = 'gmail-deterministic';

    public const EXTRACTOR_VERSION = '1';

    public function enrich(CanonicalMessage $message, string $mailboxAddress, int $threadPosition): EnrichedMessage
    {
        if ($threadPosition < 1) {
            throw new InvalidArgumentException('A posição da Mensagem na Thread deve ser positiva.');
        }

        $mailboxAddress = strtolower(trim($mailboxAddress));
        $htmlQuotedReplyStripped = false;
        if ($message->rawBodyText !== null && trim($message->rawBodyText) !== '') {
            $raw = $message->rawBodyText;
        } else {
            [$html, $htmlQuotedReplyStripped] = $this->removeHtmlQuotedBlock($message->rawBodyHtml ?? '');
            $raw = $this->htmlToText($html);
        }
        [$text, $quotedReplyStripped] = $this->stripQuotedReply($raw);
        $quotedReplyStripped = $quotedReplyStripped || $htmlQuotedReplyStripped;
        [$text, $signatureDetected] = $this->stripSignature($text);

        $participants = $this->participants($message->headers);
        $sender = $this->firstEmailForRole($participants, 'from');
        $direction = in_array('SENT', $message->labelIds, true) || ($sender !== null && $sender === $mailboxAddress)
            ? 'outbound'
            : ($sender === null ? 'unknown' : 'inbound');

        $messageId = $this->firstHeader($message->headers, 'message-id');
        $inReplyTo = $this->firstHeader($message->headers, 'in-reply-to');
        $references = $this->firstHeader($message->headers, 'references');

        return new EnrichedMessage(
            providerMessageId: $message->providerMessageId,
            contentHash: $message->contentHash,
            normalizedText: trim($text),
            participants: $participants,
            direction: $direction,
            languageCode: $this->detectLanguage($text),
            threadPosition: $threadPosition,
            attachments: $message->attachments,
            deterministicFacts: [
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'references' => $this->headerTokens($references),
                'quoted_reply_stripped' => $quotedReplyStripped,
                'signature_detected' => $signatureDetected,
            ],
            extractorName: self::EXTRACTOR_NAME,
            extractorVersion: self::EXTRACTOR_VERSION,
        );
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<\s*(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** @return array{string, bool} */
    private function removeHtmlQuotedBlock(string $html): array
    {
        $clean = preg_replace(
            '~<div\b[^>]*class\s*=\s*(["\'])[^"\']*(?:gmail_quote|gmail_attr)[^"\']*\1[^>]*>.*$~is',
            '',
            $html,
        );

        return [$clean ?? $html, $clean !== null && $clean !== $html];
    }

    /** @return array{string, bool} */
    private function stripQuotedReply(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $kept = [];
        $stripped = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:On .{1,240}wrote:|Em .{1,240}escreveu:|El .{1,240}escribi[oó]:|-----Original Message-----)\s*$/iu', $line)) {
                $stripped = true;
                break;
            }

            if (preg_match('/^\s*>/', $line)) {
                $stripped = true;
                break;
            }

            $kept[] = $line;
        }

        return [implode("\n", $kept), $stripped];
    }

    /** @return array{string, bool} */
    private function stripSignature(string $text): array
    {
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*--\s*$/', $line) || preg_match('/^\s*Sent from my\b/i', $line)) {
                return [implode("\n", $kept), true];
            }

            $kept[] = $line;
        }

        return [$text, false];
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return list<Participant>
     */
    private function participants(array $headers): array
    {
        $participants = [];
        foreach (['from', 'to', 'cc', 'bcc'] as $role) {
            foreach ($headers[$role] ?? [] as $header) {
                foreach ($this->splitAddresses($header) as [$email, $displayName]) {
                    $key = $role.'|'.$email;
                    $participants[$key] = new Participant($email, $displayName, $role);
                }
            }
        }

        ksort($participants);

        return array_values($participants);
    }

    /** @return list<array{string, ?string}> */
    private function splitAddresses(string $header): array
    {
        $chunks = [];
        $chunk = '';
        $quoted = false;
        $angleDepth = 0;

        foreach (str_split($header) as $character) {
            if ($character === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $character === '<') {
                $angleDepth++;
            } elseif (! $quoted && $character === '>' && $angleDepth > 0) {
                $angleDepth--;
            }

            if ($character === ',' && ! $quoted && $angleDepth === 0) {
                $chunks[] = $chunk;
                $chunk = '';
            } else {
                $chunk .= $character;
            }
        }
        if (trim($chunk) !== '') {
            $chunks[] = $chunk;
        }

        $addresses = [];
        foreach ($chunks as $item) {
            $displayName = null;
            if (preg_match('/^(.*?)\s*<([^<>]+)>$/u', trim($item), $matches)) {
                $displayName = trim($matches[1], " \t\n\r\0\x0B\"'");
                $email = trim($matches[2]);
            } else {
                $email = trim($item, " \t\n\r\0\x0B<>");
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[] = [strtolower($email), $displayName === '' ? null : $displayName];
            }
        }

        return $addresses;
    }

    /** @param list<Participant> $participants */
    private function firstEmailForRole(array $participants, string $role): ?string
    {
        foreach ($participants as $participant) {
            if ($participant->role === $role) {
                return $participant->normalizedEmail;
            }
        }

        return null;
    }

    private function detectLanguage(string $text): string
    {
        $scores = [
            'pt' => $this->countMatches('/\b(?:não|para|com|uma|você|obrigado|olá|estou|mensagem)\b/iu', $text),
            'en' => $this->countMatches('/\b(?:the|and|you|for|with|this|thanks|hello|please|message)\b/iu', $text),
            'es' => $this->countMatches('/\b(?:para|con|una|usted|gracias|hola|estoy|mensaje)\b/iu', $text),
        ];
        arsort($scores);
        $languages = array_keys($scores);
        $first = $scores[$languages[0]];
        $second = $scores[$languages[1]];

        return $first >= 2 && $first > $second ? $languages[0] : 'unknown';
    }

    private function countMatches(string $pattern, string $text): int
    {
        $count = preg_match_all($pattern, $text);

        return $count === false ? 0 : $count;
    }

    /** @return list<string> */
    private function headerTokens(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        preg_match_all('/<[^>]+>|[^\s]+/', $value, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeader(array $headers, string $name): ?string
    {
        $value = trim($headers[$name][0] ?? '');

        return $value === '' ? null : $value;
    }
}
