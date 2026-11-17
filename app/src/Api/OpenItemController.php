<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\OpenItemService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Klärfälle in der Oberfläche: ansehen und entscheiden. Nur mit Anmeldung eines Menschen; Agenten-Tokens ergeben 401 (ADR-010). */
final class OpenItemController
{
    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        Response::json(['open_items' => (new OpenItemService(Connection::open()))->listForHumans($actor)]);
    }

    public function resolve(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $request->json();
            $result = (new OpenItemService(Connection::open()))->resolve($id, $body['decision'] ?? null, $body['note'] ?? null, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result);
    }
}
