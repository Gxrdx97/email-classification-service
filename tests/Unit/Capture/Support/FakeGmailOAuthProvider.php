<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\GmailOAuthProvider;
use App\Domain\Capture\Data\AccessToken;
use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\GmailOAuthGrant;
use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Data\OAuthSecret;
use DateTimeImmutable;

final class FakeGmailOAuthProvider implements GmailOAuthProvider
{
    /** @var list<string> */
    public array $scopes = [];

    public string $state = '';

    public string $refreshToken = 'synthetic-refresh-token';

    public function authorizationUrl(string $state): string
    {
        $this->state = $state;

        return 'https://accounts.google.test/authorize?state='.$state;
    }

    public function exchangeCode(string $code): GmailOAuthGrant
    {
        return new GmailOAuthGrant(
            new AccessToken(new OAuthSecret('synthetic-access-token'), new DateTimeImmutable('+1 hour')),
            $this->refreshToken === '' ? null : new OAuthSecret($this->refreshToken),
            $this->scopes === [] ? GmailScopes::required() : $this->scopes,
        );
    }

    public function refresh(string $refreshToken): GmailOAuthGrant
    {
        return new GmailOAuthGrant(
            new AccessToken(new OAuthSecret('synthetic-access-token-refreshed'), new DateTimeImmutable('+1 hour')),
            new OAuthSecret($refreshToken),
            [],
        );
    }

    public function profile(string $accessToken): GmailMailboxProfile
    {
        return new GmailMailboxProfile('owner@example.test', '900');
    }

    public function revoke(string $token): void {}
}
