<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\OAuthTokenCipher;

final class FakeOAuthTokenCipher implements OAuthTokenCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'encrypted:'.$plaintext;
    }

    public function decrypt(string $ciphertext): string
    {
        return str_starts_with($ciphertext, 'encrypted:') ? substr($ciphertext, 10) : '';
    }
}
