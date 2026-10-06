<?php

declare(strict_types=1);

namespace App\Domain\Capture\Contracts;

use App\Domain\Capture\Data\OAuthConnection;

interface OAuthConnectionStore
{
    public function get(): ?OAuthConnection;

    /** Armazena refresh token já cifrado; access token não é persistido por esta porta. */
    public function save(OAuthConnection $connection): void;

    public function delete(): void;
}
