<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\UserRepository;
use Garage\Http\Request;
use Garage\Http\Response;
use Garage\Util\Env;

/** Aus DISPATCH (ADR-005). Anmeldung an `/garage/api/*`: nur Access-JWTs eines aktiven Nutzers (und wo genannt das Deploy-Token); `gmcp_`- und `goa_`-Tokens ergeben 401. */
final class ApiAuth
{
    private const AGENT_PREFIXES = ['gmcp_', 'goa_'];

    /** 503, solange `GARAGE_JWT_SECRET` oder `GARAGE_BASE_URL` fehlen — sonst liefe die Anmeldung mit einem leeren Schlüssel. */
    public static function requireConfiguration(): void
    {
        if (!Jwt::isConfigured()) {
            Response::error(
                'not_configured',
                'Die Anmeldung ist nicht eingerichtet: GARAGE_BASE_URL und GARAGE_JWT_SECRET (mindestens ' . Jwt::MIN_SECRET_LENGTH . ' Zeichen) fehlen in der .env.',
                503
            );
        }
    }

    public static function user(Request $request): Actor
    {
        $userId = self::userIdFromJwt($request);
        if ($userId === null) {
            self::reject();
        }

        $pdo = Connection::open();
        $user = (new UserRepository($pdo))->findById($userId);
        if ($user === null || $user['status'] !== UserRepository::STATUS_ACTIVE) {
            self::reject();
        }

        return Actor::forUser($userId, Actor::VIA_WEB, 'user:' . $userId, Scopes::ALL, $pdo);
    }

    /** Nur das Deploy-Token; ein JWT eines Nutzers reicht nicht. */
    public static function deployOnly(Request $request): Actor
    {
        if (!DeployToken::matches($request, Env::get('GARAGE_DEPLOY_TOKEN'))) {
            self::reject();
        }

        return Actor::deploy();
    }

    private static function userIdFromJwt(Request $request): ?int
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return null;
        }
        foreach (self::AGENT_PREFIXES as $prefix) {
            if (str_starts_with($bearer, $prefix)) {
                return null;
            }
        }

        $claims = Jwt::decode($bearer);
        if ($claims === null) {
            return null;
        }

        return (int) $claims['sub'];
    }

    private static function reject(): never
    {
        Response::error(
            'unauthorized',
            'Anmeldung fehlt oder ist abgelaufen.',
            401,
            ['WWW-Authenticate' => 'Bearer realm="garage-api"']
        );
    }
}
