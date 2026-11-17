<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use PDO;

/** Zugriff auf ein Fahrzeug: wer es sehen und wer darin schreiben darf (ADR-008). */
final class VehicleAccess
{
    private VehicleRepository $vehicles;

    public function __construct(PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
    }

    /**
     * Fremde und unbekannte Fahrzeuge sind beide `not_found`, damit sich fremde IDs nicht erraten lassen.
     *
     * @return array{id: int, garage_id: int, label: string, status: string}
     */
    public function readable(int $vehicleId, Actor $actor): array
    {
        return $this->loadOrFail($vehicleId, $actor, false);
    }

    /**
     * Sperrt die Fahrzeugzeile bis zum Ende der Transaktion, damit gleichzeitige Schreibungen nacheinander laufen,
     * und prüft dann das Schreibrecht (Rolle vor Scope).
     *
     * @return array{id: int, garage_id: int, label: string, status: string}
     */
    public function writable(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->loadOrFail($vehicleId, $actor, true);
        $this->assertCanWriteGarage($vehicle['garage_id'], $actor);

        return $vehicle;
    }

    public function assertCanWriteGarage(int $garageId, Actor $actor): void
    {
        if ($actor->canWrite($garageId)) {
            return;
        }
        if (($actor->roles[$garageId] ?? null) === Actor::ROLE_READONLY) {
            throw new DomainError('forbidden', 'Dieses Konto darf in der Garage nur lesen.', ['garage_id' => $garageId]);
        }

        throw new DomainError('forbidden', 'Dem Zugang fehlt ' . Scopes::WRITE . '.', ['required_scope' => Scopes::WRITE]);
    }

    /** @return array{id: int, garage_id: int, label: string, status: string} */
    private function loadOrFail(int $vehicleId, Actor $actor, bool $lock): array
    {
        $vehicle = $this->vehicles->find($vehicleId, $actor, $lock);
        if ($vehicle === null) {
            throw new DomainError('not_found', 'Ein Fahrzeug mit dieser ID gibt es in deinen Garagen nicht. list_vehicles zeigt die vorhandenen.', ['vehicle_id' => $vehicleId]);
        }

        return $vehicle;
    }
}
