<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Auth\Scopes;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\TokenService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Feste Zugänge für Agenten (aus DISPATCH, ADR-005): anlegen, auflisten, widerrufen — nur eigene, nur mit Anmeldung. */
final class TokenController
{
    private const DEFAULT_SCOPES = [Scopes::READ, Scopes::WRITE];

    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        Response::json(['tokens' => (new TokenService(Connection::open()))->list($actor)]);
    }

    /** Der Klartext steht nur in dieser Antwort. */
    public function create(Request $request): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $request->json();
            $name = $body['name'] ?? null;
            $scopes = $body['scopes'] ?? self::DEFAULT_SCOPES;
            $expiresDays = $body['expires_days'] ?? null;
            if (!is_string($name)) {
                throw new DomainError('invalid_value', 'name fehlt (Text, z. B. claude-code).', ['field' => 'name']);
            }
            if (!is_array($scopes) && !is_string($scopes)) {
                throw new DomainError('invalid_value', 'scopes muss eine Liste sein, z. B. ["garage:read"].', ['field' => 'scopes']);
            }
            if ($expiresDays !== null && !is_int($expiresDays)) {
                throw new DomainError('invalid_value', 'expires_days muss eine ganze Zahl oder null sein.', ['field' => 'expires_days']);
            }

            $created = (new TokenService(Connection::open()))->create($name, $scopes, $expiresDays, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($created, 201);
    }

    public function revoke(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            (new TokenService(Connection::open()))->revoke($id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::empty(204);
    }
}
