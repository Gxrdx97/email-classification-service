<?php

declare(strict_types=1);

namespace App\Http\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class GoogleOidcBearerMiddleware
{
    public const PRINCIPAL_ATTRIBUTE = 'oidc.principal';

    private GoogleOidcBearerAuthenticator $authenticator;

    public function __construct(?GoogleOidcBearerAuthenticator $authenticator = null)
    {
        $this->authenticator = $authenticator ?? new GoogleOidcBearerAuthenticator;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);
        $principal = $token === null ? null : $this->authenticator->authenticate($token);

        if ($principal === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::PRINCIPAL_ATTRIBUTE, $principal);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || strlen($authorization) > 8200) {
            return null;
        }

        if (preg_match('/\ABearer[ \t]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\z/iD', $authorization, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
