<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\InvitationService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Einladungen: anlegen mit dem Deploy-Token, ansehen und annehmen mit dem Einladungs-Token im Body (ADR-006). */
final class InvitationController
{
    private const DEFAULT_DAYS = 7;

    public function create(Request $request): never
    {
        $actor = ApiAuth::deployOnly($request);

        try {
            $body = $request->json();
            $result = $this->service()->create(
                BodyField::optionalInt($body, 'garage_id'),
                BodyField::optionalString($body, 'garage_name'),
                BodyField::requiredString($body, 'role'),
                BodyField::optionalString($body, 'email'),
                BodyField::optionalInt($body, 'days') ?? self::DEFAULT_DAYS,
                $actor
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result, 201);
    }

    public function preview(Request $request): never
    {
        try {
            $result = $this->service()->preview(BodyField::requiredString($request->json(), 'token'));
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
                BodyField::requiredString($body, 'name'),
                BodyField::requiredString($body, 'email'),
                BodyField::requiredString($body, 'password')
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        AuthController::respondWithSession($result);
    }

    private function service(): InvitationService
    {
        return new InvitationService(Connection::open());
    }
}
