<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\PasswordResetService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Passwort-Rücksetzung (ADR-026): erzeugen mit Anmeldung eines Inhabers, ansehen und einlösen mit dem Token im Body. */
final class PasswordResetController
{
    /** Der Link steht nur in dieser Antwort. */
    public function create(Request $request, int $garageId, int $userId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $created = $this->service()->create($garageId, $userId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($created, 201);
    }

    public function preview(Request $request): never
    {
        // Die Login-Bremse braucht GARAGE_JWT_SECRET.
        ApiAuth::requireConfiguration();

        try {
            $result = $this->service()->preview(BodyField::requiredString($request->json(), 'token'), $request->ip());
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result);
    }

    public function accept(Request $request): never
    {
        ApiAuth::requireConfiguration();

        try {
            $body = $request->json();
            $result = $this->service()->accept(
                BodyField::requiredString($body, 'token'),
                BodyField::requiredString($body, 'password'),
                $request->ip()
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        AuthController::respondWithSession($result);
    }

    private function service(): PasswordResetService
    {
        return new PasswordResetService(Connection::open());
    }
}
