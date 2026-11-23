<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Kilometerstände. Kilometer laufen nur vorwärts (ADR-011): ein Stand, der einem früheren größeren oder einem späteren
 * kleineren widerspricht, wird als `strittig` gespeichert, zählt nicht für die Prognose und öffnet einen Klärfall.
 */
final class OdometerService
{
    public const MAX_KM = 2000000;

    private const EXAMPLE = '{"read_on": "2026-03-02", "km": 26910}';

    private OdometerRepository $readings;
    private OpenItemRepository $openItems;
    private AuditRepository $audit;
    private VehicleAccess $access;

    public function __construct(private readonly PDO $pdo)
    {
        $this->readings = new OdometerRepository($pdo);
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->access = new VehicleAccess($pdo);
    }

    /**
     * @param array<string, mixed> $input read_on, km, note?, source, source_url?, confidence
     * @return array{reading_id: int, result: string, open_item_id?: int}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        $readOn = Validate::date('read_on', $input['read_on'] ?? null, self::EXAMPLE);
        $km = self::validKm('km', $input['km'] ?? null);
        $note = Validate::optionalText('note', $input['note'] ?? null, 1, 200, '{"note": "Tacho beim Tanken abgelesen"}');
        $provenance = Provenance::validate($input, 'odometer');

        return Transaction::run($this->pdo, function () use ($vehicleId, $readOn, $km, $note, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);

            return $this->recordInTransaction($vehicle, $readOn, $km, null, $note, $provenance, $actor);
        });
    }

    /**
     * Läuft in der Transaktion des Aufrufers, der das Fahrzeug über `VehicleAccess::writable` gesperrt hat.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @return array{reading_id: int, result: string, open_item_id?: int}
     */
    public function recordInTransaction(array $vehicle, string $readOn, string $km, ?int $eventId, ?string $note, array $provenance, Actor $actor): array
    {
        $same = $this->readings->findSame($vehicle['id'], $readOn, $km, $actor);
        if ($same !== null) {
            return $this->repeated($vehicle, $same, $eventId, $provenance, $actor);
        }

        $conflicts = $this->readings->findConflicts($vehicle['id'], $readOn, $km, $actor);
        if ($conflicts === []) {
            $readingId = $this->readings->insert($vehicle['garage_id'], $vehicle['id'], $readOn, $km, $eventId, $note, OdometerRepository::STATUS_VALID, $provenance, $actor);
            $this->audit->record($actor, 'odometer.create', sprintf('odometer_readings:%d', $readingId), $vehicle['garage_id'], null, ['read_on' => $readOn, 'km' => $km, 'event_id' => $eventId], $vehicle['id']);

            return ['reading_id' => $readingId, 'result' => RecordMerger::RESULT_CREATED];
        }

        return $this->dispute($vehicle, $readOn, $km, $eventId, $note, $conflicts, $provenance, $actor);
    }

    /**
     * Gültige Stände, älteste zuerst — Grundlage der Kilometerprognose.
     *
     * @return list<array{id: int, read_on: string, km: int}>
     */
    public function validReadings(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        return $this->readings->listValid($vehicleId, $actor);
    }

    /**
     * Gültige Stände mit Notiz und Herkunft, älteste zuerst.
     *
     * @return list<array{id: int, read_on: string, km: int, note: string|null, source: string, confidence: string}>
     */
    public function validReadingsDetailed(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        return $this->readings->listValidDetailed($vehicleId, $actor);
    }

    /** @return array{read_on: string, km: int, source: string, source_url: string|null, confidence: string}|null */
    public function latestValid(int $vehicleId, Actor $actor): ?array
    {
        $this->access->readable($vehicleId, $actor);

        return $this->readings->latestValid($vehicleId, $actor);
    }

    public static function validKm(string $field, mixed $raw): string
    {
        return Validate::int($field, $raw, sprintf('{"%s": 26910}', $field), 0, self::MAX_KM);
    }

    /**
     * Derselbe Stand noch einmal: nichts doppelt anlegen; eine höhere Konfidenz hebt die Herkunft.
     *
     * @param array{id: int, reading_status: string, confidence: string, event_id: int|null} $same
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @return array{reading_id: int, result: string, open_item_id?: int}
     */
    private function repeated(array $vehicle, array $same, ?int $eventId, array $provenance, Actor $actor): array
    {
        $result = RecordMerger::RESULT_UNCHANGED;
        if (Provenance::isHigher($provenance['confidence'], $same['confidence'])) {
            $this->readings->raiseProvenance($same['id'], $vehicle['garage_id'], $provenance);
            $this->audit->record(
                $actor,
                'odometer.update',
                sprintf('odometer_readings:%d', $same['id']),
                $vehicle['garage_id'],
                ['confidence' => $same['confidence']],
                ['confidence' => $provenance['confidence']],
                $vehicle['id']
            );
            $result = RecordMerger::RESULT_UPDATED;
        }
        if ($eventId !== null && $same['event_id'] === null) {
            $this->readings->linkEvent($same['id'], $vehicle['garage_id'], $eventId);
        }

        $outcome = ['reading_id' => $same['id'], 'result' => $result];
        if ($same['reading_status'] === OdometerRepository::STATUS_DISPUTED) {
            $openItemId = $this->openDisputeOf($same['id'], $actor);
            $outcome['result'] = RecordMerger::RESULT_DISPUTE;
            if ($openItemId !== null) {
                $outcome['open_item_id'] = $openItemId;
            }
        }

        return $outcome;
    }

    private function openDisputeOf(int $readingId, Actor $actor): ?int
    {
        foreach ($this->openItems->findOpenByRef('odometer_readings', $readingId, $actor) as $openItem) {
            return $openItem['id'];
        }

        return null;
    }

    /**
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param list<array{id: int, read_on: string, km: int}> $conflicts
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @return array{reading_id: int, result: string, open_item_id: int}
     */
    private function dispute(array $vehicle, string $readOn, string $km, ?int $eventId, ?string $note, array $conflicts, array $provenance, Actor $actor): array
    {
        $readingId = $this->readings->insert($vehicle['garage_id'], $vehicle['id'], $readOn, $km, $eventId, $note, OdometerRepository::STATUS_DISPUTED, $provenance, $actor);
        $conflictIds = array_map(static fn (array $conflict): int => $conflict['id'], $conflicts);
        $first = $conflicts[0];
        $text = sprintf(
            'Kilometerstand %s am %s passt nicht zu %s am %s. Kilometer laufen nur vorwärts; ein Mensch entscheidet, welcher Stand gilt.',
            self::formatKm((int) $km),
            $readOn,
            self::formatKm($first['km']),
            $first['read_on']
        );
        $openItemId = $this->openItems->insert(
            $vehicle['garage_id'],
            $vehicle['id'],
            OpenItemRepository::KIND_CONFLICT,
            $text,
            'odometer_readings',
            $readingId,
            $actor,
            ['conflicts_with' => $conflictIds]
        );
        $this->audit->record(
            $actor,
            'odometer.dispute',
            sprintf('odometer_readings:%d', $readingId),
            $vehicle['garage_id'],
            null,
            ['read_on' => $readOn, 'km' => $km, 'event_id' => $eventId, 'conflicts_with' => $conflictIds, 'open_item_id' => $openItemId],
            $vehicle['id']
        );

        return ['reading_id' => $readingId, 'result' => RecordMerger::RESULT_DISPUTE, 'open_item_id' => $openItemId];
    }

    private static function formatKm(int $km): string
    {
        return number_format($km, 0, ',', '.') . ' km';
    }
}
