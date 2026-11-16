<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\GarageRepository;
use Garage\Domain\UserRepository;
use Garage\Http\Request;
use Garage\Http\Response;

/** `GET /garage/api/me`: wer bin ich und in welchen Garagen mit welcher Rolle. */
final class MeController
{
    public function show(Request $request): never
    {
        $actor = ApiAuth::user($request);
        $userId = (int) $actor->userId;

        $pdo = Connection::open();
        $user = (new UserRepository($pdo))->findById($userId);
        if ($user === null) {
            Response::error('unauthorized', 'Anmeldung fehlt oder ist abgelaufen.', 401);
        }

        Response::json([
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
            'garages' => (new GarageRepository($pdo))->listForUser($userId),
        ]);
    }
}
