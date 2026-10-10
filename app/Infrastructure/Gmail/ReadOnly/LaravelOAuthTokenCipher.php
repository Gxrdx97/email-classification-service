<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\ReadOnly;

use App\Domain\Capture\Contracts\OAuthTokenCipher;
use App\Domain\Capture\Exceptions\OAuthFailure;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Throwable;

final readonly class LaravelOAuthTokenCipher implements OAuthTokenCipher
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new OAuthFailure('O refresh token Gmail está vazio.');
        }

        try {
            return $this->encrypter->encryptString($plaintext);
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível cifrar a credencial Gmail.');
        }
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            $plaintext = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível decifrar a credencial Gmail.');
        }

        if ($plaintext === '') {
            throw new OAuthFailure('A credencial Gmail cifrada está vazia.');
        }

        return $plaintext;
    }
}
