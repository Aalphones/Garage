<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\LifecycleService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Datensätze löschen und wiederherstellen (ADR-019); nur mit Anmeldung eines Menschen, Rolle Inhaber oder Mitglied. */
final class RecordController
{
    public function delete(Request $request, string $type, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            // Der Grund ist freiwillig; ein leerer Body ist erlaubt.
            $body = $request->body() === '' ? [] : $request->json();
            $result = (new LifecycleService(Connection::open()))->deleteRecord($type, $id, $body['reason'] ?? null, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result);
    }

    public function restore(Request $request, string $type, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $result = (new LifecycleService(Connection::open()))->restoreRecord($type, $id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result);
    }
}
