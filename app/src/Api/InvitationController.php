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
                self::optionalInt($body, 'garage_id'),
                self::optionalString($body, 'garage_name'),
                self::requiredString($body, 'role'),
                self::optionalString($body, 'email'),
                self::optionalInt($body, 'days') ?? self::DEFAULT_DAYS,
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
            $result = $this->service()->preview(self::requiredString($request->json(), 'token'));
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
                self::requiredString($body, 'token'),
                self::requiredString($body, 'name'),
                self::requiredString($body, 'email'),
                self::requiredString($body, 'password')
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

    /** @param array<string, mixed> $body */
    private static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new DomainError('invalid_value', $field . ' fehlt oder ist kein Text.', ['field' => $field]);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalString(array $body, string $field): ?string
    {
        if (!isset($body[$field])) {
            return null;
        }

        return self::requiredString($body, $field);
    }

    /** @param array<string, mixed> $body */
    private static function optionalInt(array $body, string $field): ?int
    {
        if (!isset($body[$field])) {
            return null;
        }
        if (!is_int($body[$field])) {
            throw new DomainError('invalid_value', $field . ' muss eine ganze Zahl sein.', ['field' => $field]);
        }

        return $body[$field];
    }
}
