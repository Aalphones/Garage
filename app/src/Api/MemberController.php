<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\InvitationService;
use Garage\Domain\MemberService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Mitglieder und Einladungen einer Garage: nur mit Anmeldung eines Menschen, nie mit Agenten-Token (ADR-026). */
final class MemberController
{
    public function list(Request $request, int $garageId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $result = (new MemberService(Connection::open()))->list($garageId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result);
    }

    /** Der Link steht nur in dieser Antwort. */
    public function invite(Request $request, int $garageId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $request->json();
            $created = (new InvitationService(Connection::open()))->createForGarage(
                $garageId,
                BodyField::requiredString($body, 'role'),
                BodyField::optionalString($body, 'email'),
                BodyField::optionalInt($body, 'days') ?? InvitationService::DEFAULT_DAYS,
                $actor
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($created, 201);
    }

    public function revokeInvitation(Request $request, int $garageId, int $invitationId): never
    {
        $actor = ApiAuth::user($request);

        try {
            (new InvitationService(Connection::open()))->revoke($garageId, $invitationId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::empty(204);
    }

    public function changeRole(Request $request, int $garageId, int $userId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $role = BodyField::requiredString($request->json(), 'role');
            $member = (new MemberService(Connection::open()))->changeRole($garageId, $userId, $role, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json(['member' => $member]);
    }

    public function remove(Request $request, int $garageId, int $userId): never
    {
        $actor = ApiAuth::user($request);

        try {
            (new MemberService(Connection::open()))->remove($garageId, $userId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::empty(204);
    }
}
