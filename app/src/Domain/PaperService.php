<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Papiere eines Fahrzeugs für `get_papers` und den Reiter Papiere: Verträge, Stichtage, Plaketten, Zubehör, Notfalldaten. */
final class PaperService
{
    private VehicleAccess $access;
    private ContractService $contracts;
    private PermitService $permits;
    private AccessoryService $accessories;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->contracts = new ContractService($pdo);
        $this->permits = new PermitService($pdo);
        $this->accessories = new AccessoryService($pdo);
    }

    /**
     * Stichtage und Notfalldaten bleiben leer (Liste bzw. `null`), bis die Stichtag-Rechnung steht.
     *
     * @return array{contracts: list<array<string, mixed>>, deadlines: list<array<string, mixed>>, permits: list<array<string, mixed>>, accessories: list<array<string, mixed>>, emergency: array<string, mixed>|null}
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        return [
            'contracts' => $this->contracts->forVehicle($vehicleId, $actor),
            'deadlines' => [],
            'permits' => $this->permits->forVehicle($vehicleId, $actor),
            'accessories' => $this->accessories->forVehicle($vehicleId, $actor),
            'emergency' => null,
        ];
    }
}
