<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\ReadOnly;

use App\Domain\Capture\Contracts\OAuthStateStore;
use App\Domain\Capture\Exceptions\OAuthFailure;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

final readonly class LaravelOAuthStateStore implements OAuthStateStore
{
    public function __construct(private Repository $cache) {}

    public function issue(string $stateHash, DateTimeImmutable $expiresAt): void
    {
        $this->assertHash($stateHash);
        $ttl = $expiresAt->getTimestamp() - time();
        if ($ttl < 1 || ! $this->cache->add($this->key($stateHash), true, $ttl)) {
            throw new OAuthFailure('Não foi possível persistir o state OAuth de forma segura.');
        }
    }

    public function consume(string $stateHash): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $stateHash)) {
            return false;
        }

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new OAuthFailure('O armazenamento de state OAuth não oferece consumo atômico.');
        }

        try {
            return (bool) $store
                ->lock($this->key($stateHash).':consume', 5)
                ->block(2, fn (): bool => $this->cache->pull($this->key($stateHash)) !== null);
        } catch (Throwable) {
            throw new OAuthFailure('Não foi possível consumir o state OAuth de forma segura.');
        }
    }

    private function assertHash(string $stateHash): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $stateHash)) {
            throw new OAuthFailure('O hash do state OAuth é inválido.');
        }
    }

    private function key(string $stateHash): string
    {
        return 'gmail-oauth-state:'.strtolower($stateHash);
    }
}
