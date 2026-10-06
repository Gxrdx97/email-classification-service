<?php

declare(strict_types=1);

namespace App\Http\Auth\Contracts;

use Firebase\JWT\Key;

interface JwksKeySetProvider
{
    public function keyFor(string $keyId): ?Key;
}
