<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\GmailOAuthGrant;

interface GmailOAuthProvider
{
    public function authorizationUrl(string $state): string;

    public function exchangeCode(string $code): GmailOAuthGrant;

    public function refresh(string $refreshToken): GmailOAuthGrant;

    public function profile(string $accessToken): GmailMailboxProfile;

    public function revoke(string $token): void;
}
