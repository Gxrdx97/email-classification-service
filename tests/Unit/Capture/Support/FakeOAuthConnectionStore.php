<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Contracts\OAuthConnectionStore;
use App\Domain\Capture\Data\OAuthConnection;

final class FakeOAuthConnectionStore implements OAuthConnectionStore
{
    public ?OAuthConnection $connection = null;

    public function get(): ?OAuthConnection
    {
        return $this->connection;
    }

    public function save(OAuthConnection $connection): void
    {
        $this->connection = $connection;
    }

    public function delete(): void
    {
        $this->connection = null;
    }
}
