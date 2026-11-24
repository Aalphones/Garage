<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Papiere eines Fahrzeugs für `get_papers` und den Reiter Papiere: Verträge, Stichtage, Plaketten, Zubehör, Notfalldaten. */
final class PaperService
{
    private const KIND_TAX = 'steuer';
    private const KIND_INSURANCE = 'versicherung';
    private const KIND_CLUB = 'club';
    private const LOCAL_TIMEZONE = 'Europe/Berlin';

    private VehicleAccess $access;
    private ContractService $contracts;
    private PermitService $permits;
    private AccessoryService $accessories;
    private DeadlineService $deadlines;
    private DueService $dues;
    private VehicleRepository $vehicles;
    private RegistrationService $registrations;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->contracts = new ContractService($pdo);
        $this->permits = new PermitService($pdo);
        $this->accessories = new AccessoryService($pdo);
        $this->deadlines = new DeadlineService($pdo);
        $this->dues = new DueService($pdo);
        $this->vehicles = new VehicleRepository($pdo);
        $this->registrations = new RegistrationService($pdo);
    }

    /**
     * Jeder Vertrag trägt eine Prüfzeile `check` (`null`, wenn es für ihn nichts nachzurechnen gibt).
     *
     * @return array{contracts: list<array<string, mixed>>, deadlines: list<array<string, mixed>>, permits: list<array<string, mixed>>, accessories: list<array<string, mixed>>, emergency: array<string, mixed>}
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);
        $contracts = $this->contracts->forVehicle($vehicleId, $actor);

        return [
            'contracts' => $this->withChecks($vehicleId, $contracts, $actor),
            'deadlines' => $this->deadlines->forVehicle($vehicleId, $actor),
            'permits' => $this->permits->forVehicle($vehicleId, $actor),
            'accessories' => $this->accessories->forVehicle($vehicleId, $actor),
            'emergency' => $this->emergencyFrom($vehicleId, $contracts, $actor),
        ];
    }

    /**
     * Notfallkarte: Kennzeichen, FIN, Versicherung und Pannenhilfe auf einen Blick (ADR-025).
     *
     * @return array{vehicle_id: int, label: string, plate: string|null, fin: string|null, insurer: array{provider: string, contract_number: string|null, hotline: string|null}|null, breakdown: array{provider: string, member_number: string|null, hotline: string|null}|null, as_of: string}
     */
    public function emergency(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        return $this->emergencyFrom($vehicleId, $this->contracts->forVehicle($vehicleId, $actor), $actor);
    }

    /**
     * @param list<array<string, mixed>> $contracts Ausgabe von `ContractService::forVehicle`
     * @return array{vehicle_id: int, label: string, plate: string|null, fin: string|null, insurer: array{provider: string, contract_number: string|null, hotline: string|null}|null, breakdown: array{provider: string, member_number: string|null, hotline: string|null}|null, as_of: string}
     */
    private function emergencyFrom(int $vehicleId, array $contracts, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);
        $today = self::today();
        $running = array_values(array_filter($contracts, static fn (array $contract): bool => $contract['ends_on'] === null || $contract['ends_on'] >= $today));

        $insurer = null;
        foreach ($running as $contract) {
            if ($contract['kind'] === self::KIND_INSURANCE && $contract['level'] === ContractService::LEVEL_VEHICLE) {
                $insurer = ['provider' => $contract['provider'], 'contract_number' => $contract['contract_number'], 'hotline' => $contract['hotline']];
                break;
            }
        }
        // Eigene Verträge stehen vor denen der Garage: ein Fahrzeug-Club schlägt den Garagen-Club.
        $breakdown = null;
        foreach ($running as $contract) {
            if ($contract['kind'] === self::KIND_CLUB) {
                $breakdown = ['provider' => $contract['provider'], 'member_number' => $contract['member_number'], 'hotline' => $contract['hotline']];
                break;
            }
        }

        return [
            'vehicle_id' => $vehicleId,
            'label' => $vehicle['label'],
            'plate' => $this->validPlate($vehicleId, $today, $actor),
            'fin' => $this->vehicles->currentValues([$vehicleId], ['fin'], $actor)[$vehicleId]['fin'] ?? null,
            'insurer' => $insurer,
            'breakdown' => $breakdown,
            'as_of' => $today,
        ];
    }

    private function validPlate(int $vehicleId, string $today, Actor $actor): ?string
    {
        foreach ($this->registrations->list($vehicleId, $actor) as $registration) {
            $isValid = $registration['valid_from'] <= $today && ($registration['valid_to'] === null || $registration['valid_to'] >= $today);
            if ($isValid) {
                return $registration['plate'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $contracts
     * @return list<array<string, mixed>>
     */
    private function withChecks(int $vehicleId, array $contracts, Actor $actor): array
    {
        $facts = null;
        $forecast = false;
        $today = self::today();

        $checked = [];
        foreach ($contracts as $contract) {
            $latestTerm = $contract['terms'][0] ?? null;
            if ($contract['level'] === ContractService::LEVEL_GARAGE) {
                $term = self::termOn($contract['terms'], $today);
                $contract['check'] = ContractCheck::garageShare($contract['share'], $term === null ? null : self::cents($term['amount_eur']));
            } elseif ($contract['kind'] === self::KIND_TAX) {
                $facts ??= $this->vehicles->currentValues([$vehicleId], ContractCheck::TAX_FACTS, $actor)[$vehicleId] ?? [];
                $contract['check'] = ContractCheck::tax($facts, $latestTerm === null ? null : ['amount_cents' => self::cents($latestTerm['amount_eur']), 'days' => self::termDays($latestTerm)]);
            } elseif ($contract['kind'] === self::KIND_INSURANCE) {
                if ($forecast === false) {
                    $forecast = $this->dues->forecastFor($vehicleId, $actor);
                }
                $contract['check'] = ContractCheck::insurance($forecast['per_day'] ?? null, $latestTerm['declared_km_per_year'] ?? null);
            } else {
                $contract['check'] = null;
            }
            $checked[] = $contract;
        }

        return $checked;
    }

    /**
     * Die heute gültige Periode, sonst die jüngste — dieselbe Wahl wie `share.eur_per_year`.
     *
     * @param list<array<string, mixed>> $terms absteigend nach Beginn
     * @return array<string, mixed>|null
     */
    private static function termOn(array $terms, string $today): ?array
    {
        foreach ($terms as $term) {
            if ($term['valid_from'] <= $today && $term['valid_to'] >= $today) {
                return $term;
            }
        }

        return $terms[0] ?? null;
    }

    /** @param array<string, mixed> $term */
    private static function termDays(array $term): int
    {
        return (int) (new DateTimeImmutable((string) $term['valid_from']))->diff(new DateTimeImmutable((string) $term['valid_to']))->days + 1;
    }

    private static function cents(string $euro): int
    {
        return (int) Validate::euroToCents('amount_eur', $euro, true);
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
    }
}
