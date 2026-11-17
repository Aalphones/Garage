<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\InboxService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Eingang in der Oberfläche: ansehen, füllen, verwerfen, zurückholen. Nur mit Anmeldung eines Menschen (ADR-016). */
final class InboxController
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        Response::json(['items' => $this->service()->listForHumans($actor)]);
    }

    public function create(Request $request): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $request->json();
            $result = $this->service()->create($body['document_ids'] ?? null, $body['vehicle_id'] ?? null, $body['note'] ?? null, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result, 201);
    }

    public function discard(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $this->service()->discard($id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json(['id' => $id, 'status' => 'verworfen']);
    }

    public function restore(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $this->service()->restore($id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json(['id' => $id, 'status' => 'neu']);
    }

    private function service(): InboxService
    {
        return new InboxService(Connection::open(), $this->config);
    }
}
