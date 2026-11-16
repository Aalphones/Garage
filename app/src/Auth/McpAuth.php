<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Domain\TokenService;
use Garage\Domain\UserRepository;
use Garage\Http\Request;
use Garage\OAuth\TokenIssuer;

/** Anmeldung am MCP-Endpunkt: feste Tokens `gmcp_` und OAuth-Access-Tokens `goa_`; JWTs fallen hier durch (aus DISPATCH, ADR-005). */
final class McpAuth
{
    private const PREFIX = 'gmcp_';

    /** @return array{id: int, user_id: int, name: string, scopes: list<string>, kind: 'mcp'|'oauth'}|null */
    public static function authenticate(Request $request, TokenService $tokens, TokenIssuer $oauth, UserRepository $users): ?array
    {
        $plain = $request->bearerToken();
        if ($plain === null) {
            return null;
        }

        $auth = null;
        if (str_starts_with($plain, self::PREFIX)) {
            $found = $tokens->authenticate($plain);
            $auth = $found === null ? null : $found + ['kind' => 'mcp'];
        } elseif (str_starts_with($plain, TokenIssuer::ACCESS_PREFIX)) {
            $found = $oauth->authenticate($plain);
            $auth = $found === null ? null : $found + ['kind' => 'oauth'];
        }

        // Ein gesperrter Nutzer arbeitet mit seinen festen Tokens nicht weiter; die Token-Abfrage prüft den Status nicht selbst.
        if ($auth === null || !self::isActive($users, $auth['user_id'])) {
            return null;
        }

        return $auth;
    }

    private static function isActive(UserRepository $users, int $userId): bool
    {
        $user = $users->findById($userId);

        return $user !== null && $user['status'] === UserRepository::STATUS_ACTIVE;
    }
}
