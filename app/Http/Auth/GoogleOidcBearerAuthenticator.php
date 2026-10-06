<?php

declare(strict_types=1);

namespace App\Http\Auth;

use App\Http\Auth\Contracts\JwksKeySetProvider;
use App\Infrastructure\Oidc\GoogleJwksKeySetProvider;
use Firebase\JWT\JWT;
use JsonException;
use Throwable;

final readonly class GoogleOidcBearerAuthenticator
{
    private JwksKeySetProvider $keySetProvider;

    public function __construct(?JwksKeySetProvider $keySetProvider = null)
    {
        $this->keySetProvider = $keySetProvider ?? new GoogleJwksKeySetProvider;
    }

    public function authenticate(string $token): ?GoogleOidcPrincipal
    {
        if ($token === '' || strlen($token) > 8192) {
            return null;
        }

        $configuration = $this->configuration();
        if ($configuration === null) {
            return null;
        }

        try {
            $keyId = $this->keyIdFromHeader($token);
            if ($keyId === null) {
                return null;
            }

            $key = $this->keySetProvider->keyFor($keyId);
            if ($key === null) {
                return null;
            }

            $claims = JWT::decode($token, $key);
            if (! $this->validClaims($claims, $configuration)) {
                return null;
            }

            return new GoogleOidcPrincipal($claims->sub, $claims->email);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{audience: string, allowed_emails: list<string>, allowed_subs: list<string>}|null */
    private function configuration(): ?array
    {
        $audience = config('oidc.audience');
        $emails = config('oidc.allowed_emails', []);
        $subjects = config('oidc.allowed_subs', []);

        if (! is_string($audience) || trim($audience) === '') {
            return null;
        }

        $allowedEmails = [];
        foreach (is_array($emails) ? $emails : [] as $email) {
            if (is_string($email) && trim($email) !== '') {
                $allowedEmails[] = strtolower(trim($email));
            }
        }

        $allowedSubjects = [];
        foreach (is_array($subjects) ? $subjects : [] as $subject) {
            if (is_string($subject) && trim($subject) !== '') {
                $allowedSubjects[] = trim($subject);
            }
        }

        if ($allowedEmails === [] && $allowedSubjects === []) {
            return null;
        }

        return [
            'audience' => $audience,
            'allowed_emails' => $allowedEmails,
            'allowed_subs' => $allowedSubjects,
        ];
    }

    private function keyIdFromHeader(string $token): ?string
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        foreach ($segments as $segment) {
            if (preg_match('/\A[A-Za-z0-9_-]+\z/D', $segment) !== 1) {
                return null;
            }
        }

        $headerSegment = $segments[0];
        $padding = (4 - (strlen($headerSegment) % 4)) % 4;
        $decodedHeader = base64_decode(
            strtr($headerSegment, '-_', '+/').str_repeat('=', $padding),
            true,
        );

        if (! is_string($decodedHeader)) {
            return null;
        }

        try {
            $header = json_decode($decodedHeader, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $header instanceof \stdClass
            || ($header->alg ?? null) !== 'RS256'
            || property_exists($header, 'crit')
            || property_exists($header, 'b64')) {
            return null;
        }

        $keyId = $header->kid ?? null;

        return is_string($keyId)
            && strlen($keyId) <= 256
            && preg_match('/\A[A-Za-z0-9._-]+\z/D', $keyId) === 1
            ? $keyId
            : null;
    }

    /** @param array{audience: string, allowed_emails: list<string>, allowed_subs: list<string>} $configuration */
    private function validClaims(\stdClass $claims, array $configuration): bool
    {
        $now = time();
        if (! is_string($claims->iss ?? null)
            || ! in_array($claims->iss, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ! is_string($claims->aud ?? null)
            || ! hash_equals($configuration['audience'], $claims->aud)
            || ! is_int($claims->exp ?? null)
            || $claims->exp <= $now
            || ! is_int($claims->iat ?? null)
            || $claims->iat > $now
            || (property_exists($claims, 'nbf') && (! is_int($claims->nbf) || $claims->nbf > $now))
            || ! is_string($claims->sub ?? null)
            || trim($claims->sub) === ''
            || ! is_string($claims->email ?? null)
            || filter_var($claims->email, FILTER_VALIDATE_EMAIL) === false
            || ($claims->email_verified ?? null) !== true) {
            return false;
        }

        $email = strtolower($claims->email);
        $emailAllowed = false;
        foreach ($configuration['allowed_emails'] as $allowedEmail) {
            if (hash_equals($allowedEmail, $email)) {
                $emailAllowed = true;
                break;
            }
        }

        $subjectAllowed = false;
        foreach ($configuration['allowed_subs'] as $allowedSubject) {
            if (hash_equals($allowedSubject, $claims->sub)) {
                $subjectAllowed = true;
                break;
            }
        }

        return $emailAllowed || $subjectAllowed;
    }
}
