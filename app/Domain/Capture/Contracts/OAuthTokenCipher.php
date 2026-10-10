<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

interface OAuthTokenCipher
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
