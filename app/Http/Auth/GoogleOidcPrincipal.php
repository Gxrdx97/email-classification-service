<?php

declare(strict_types=1);

namespace App\Http\Auth;

final readonly class GoogleOidcPrincipal
{
    public function __construct(
        public string $subject,
        public string $email,
    ) {}
}
