<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Garage\Db\Transaction;
use PDO;

/**
 * Löschen, Wiederherstellen und Fahrzeugstatus durch Menschen (ADR-019). Löschen ist ein Status mit Grund, nie ein
 * hartes Löschen; jede Aktion schreibt einen Audit-Eintrag mit vorher/nachher. Agenten haben hier keinen Zugang (Regel 5).
 */
final class LifecycleService
{
    public const RESTORE_DAYS = 30;

    /** Einzige Quelle für Tabellennamen; der Typ aus der Route wird nur hierüber übersetzt. */
    public const RECORD_TABLES = [
        'event' => 'events',
        'measurement' => 'measurements',
        'tire_set' => 'tire_sets',
        'part' => 'parts',
        'damage' => 'damages',
        'recall' => 'recalls',
        'note' => 'notes',
        'contract' => 'contracts',
        'contract_event' => 'contract_events',
        'accessory' => 'accessories',
        'permit' => 'permits',
        'fuel' => 'fuel_logs',
        'expense' => 'expenses',
        'valuation' => 'valuations',
        'listing' => 'market_listings',
    ];

    public const VEHICLE_STATUSES = ['aktiv', 'verkauft', 'stillgelegt'];

    private const STATUS_ACTIVE = 'aktiv';
    private const STATUS_DELETED = 'geloescht';
    private const NOTES_TABLE = 'notes';
    private const EVENTS_TABLE = 'events';
    private const FUEL_TABLE = 'fuel_logs';
    private const LOCAL_TIMEZONE = 'Europe/Berlin';
    private const MAX_REASON = 200;
    private const REASON_EXAMPLE = '{"reason": "Doppelt erfasst"}';

    private VehicleRepository $vehicles;
    private VehicleAccess $access;
    private RecordRepository $records;
    private ContractRepository $contracts;
    private NoteRepository $notes;
    private OdometerRepository $readings;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
        $this->access = new VehicleAccess($pdo);
        $this->records = new RecordRepository($pdo);
        $this->contracts = new ContractRepository($pdo);
        $this->notes = new NoteRepository($pdo);
        $this->readings = new OdometerRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /** @return array{id: int, status: string, status_reason: string|null} */
    public function setVehicleStatus(int $vehicleId, mixed $status, mixed $reason, Actor $actor): array
    {
        self::assertHuman($actor);
        $status = Validate::enum('status', $status, self::VEHICLE_STATUSES);
        $reason = Validate::optionalText('reason', $reason, 1, self::MAX_REASON, '{"status": "verkauft", "reason": "An Privat verkauft"}');

        return Transaction::run($this->pdo, function () use ($vehicleId, $status, $reason, $actor): array {
            $this->access->writable($vehicleId, $actor);
            $vehicle = $this->ownedVehicle($vehicleId, $actor, 'Den Status eines Fahrzeugs ändert nur der Inhaber der Garage.');

            $this->vehicles->setStatus($vehicleId, $status, $reason, $actor);
            $this->vehicles->setStatusSince($vehicleId, $status === self::STATUS_ACTIVE ? null : self::today(), $actor);
            $this->recordVehicleAudit($actor, 'vehicle.status', $vehicle, $status, $reason);

            return ['id' => $vehicleId, 'status' => $status, 'status_reason' => $reason];
        });
    }

    /** @return array{id: int, status: string, restorable_until: string} */
    public function deleteVehicle(int $vehicleId, mixed $reason, Actor $actor): array
    {
        self::assertHuman($actor);
        $reason = Validate::text('reason', $reason, 1, self::MAX_REASON, self::REASON_EXAMPLE);

        return Transaction::run($this->pdo, function () use ($vehicleId, $reason, $actor): array {
            $this->access->writable($vehicleId, $actor);
            $vehicle = $this->ownedVehicle($vehicleId, $actor, 'Ein Fahrzeug löscht nur der Inhaber der Garage.');

            $deletedAt = $this->vehicles->setStatus($vehicleId, self::STATUS_DELETED, $reason, $actor);
            $this->recordVehicleAudit($actor, 'vehicle.delete', $vehicle, self::STATUS_DELETED, $reason);

            return ['id' => $vehicleId, 'status' => self::STATUS_DELETED, 'restorable_until' => self::restorableUntil($deletedAt)];
        });
    }

    /** @return array{id: int, status: string} */
    public function restoreVehicle(int $vehicleId, Actor $actor): array
    {
        self::assertHuman($actor);

        return Transaction::run($this->pdo, function () use ($vehicleId, $actor): array {
            $vehicle = $this->ownedVehicle($vehicleId, $actor, 'Ein gelöschtes Fahrzeug stellt nur der Inhaber der Garage wieder her.');
            if ($vehicle['status'] !== self::STATUS_DELETED) {
                throw new DomainError('not_deleted', 'Dieses Fahrzeug ist nicht gelöscht.', ['vehicle_id' => $vehicleId, 'status' => $vehicle['status']]);
            }
            if (!$vehicle['is_restorable']) {
                throw new DomainError('restore_expired', sprintf('Das Fahrzeug wurde vor mehr als %d Tagen gelöscht und lässt sich nicht mehr wiederherstellen.', self::RESTORE_DAYS), ['vehicle_id' => $vehicleId]);
            }

            $this->vehicles->setStatus($vehicleId, self::STATUS_ACTIVE, null, $actor);
            $this->vehicles->setStatusSince($vehicleId, null, $actor);
            $this->recordVehicleAudit($actor, 'vehicle.restore', $vehicle, self::STATUS_ACTIVE, null);

            return ['id' => $vehicleId, 'status' => self::STATUS_ACTIVE];
        });
    }

    /** @return array{type: string, id: int, status: string} */
    public function deleteRecord(string $type, int $id, mixed $reason, Actor $actor): array
    {
        self::assertHuman($actor);
        $table = self::tableFor($type);
        $reason = Validate::optionalText('reason', $reason, 1, self::MAX_REASON, self::REASON_EXAMPLE);

        return Transaction::run($this->pdo, function () use ($type, $table, $id, $reason, $actor): array {
            $record = $this->lockedRecord($table, $id, $actor);
            if ($record['status'] === self::STATUS_DELETED) {
                throw new DomainError('already_deleted', 'Dieser Eintrag ist bereits gelöscht.', ['type' => $type, 'id' => $id]);
            }

            $this->writeStatus($table, $record, self::STATUS_DELETED, $reason, $actor);
            $after = ['status' => self::STATUS_DELETED, 'status_reason' => $reason];
            if ($table === self::EVENTS_TABLE) {
                $after['odometer_reading_ids'] = $this->readings->setStatusForEvent($id, self::STATUS_ACTIVE, self::STATUS_DELETED, $reason, $actor);
            }
            if ($table === self::FUEL_TABLE) {
                $after['odometer_reading_ids'] = $this->setFuelReadingStatus($record['row'], self::STATUS_ACTIVE, self::STATUS_DELETED, $reason, $actor);
            }
            $this->recordRecordAudit($actor, 'record.delete', $table, $record, $after);

            return ['type' => $type, 'id' => $id, 'status' => self::STATUS_DELETED];
        });
    }

    /** @return array{type: string, id: int, status: string} */
    public function restoreRecord(string $type, int $id, Actor $actor): array
    {
        self::assertHuman($actor);
        $table = self::tableFor($type);

        return Transaction::run($this->pdo, function () use ($type, $table, $id, $actor): array {
            $record = $this->lockedRecord($table, $id, $actor);
            if ($record['status'] !== self::STATUS_DELETED) {
                throw new DomainError('not_deleted', 'Dieser Eintrag ist nicht gelöscht.', ['type' => $type, 'id' => $id]);
            }
            // Notizen brauchen keine Prüfung: der eindeutige Schlüssel (Fahrzeug, Thema) lässt kein zweites gleiches Thema zu.
            if ($table !== self::NOTES_TABLE && $this->hasActiveDuplicate($table, $id, $record['row'], $actor)) {
                throw new DomainError('conflict', 'Es gibt inzwischen einen gleichen Eintrag.', ['type' => $type, 'id' => $id]);
            }

            $this->writeStatus($table, $record, self::STATUS_ACTIVE, null, $actor);
            $after = ['status' => self::STATUS_ACTIVE, 'status_reason' => null];
            if ($table === self::EVENTS_TABLE) {
                $after['odometer_reading_ids'] = $this->readings->setStatusForEvent($id, self::STATUS_DELETED, self::STATUS_ACTIVE, null, $actor);
            }
            if ($table === self::FUEL_TABLE) {
                $after['odometer_reading_ids'] = $this->setFuelReadingStatus($record['row'], self::STATUS_DELETED, self::STATUS_ACTIVE, null, $actor);
            }
            $this->recordRecordAudit($actor, 'record.restore', $table, $record, $after);

            return ['type' => $type, 'id' => $id, 'status' => self::STATUS_ACTIVE];
        });
    }

    /**
     * Der Kilometerstand einer Tankung (`odometer_reading_id`) folgt ihrem Status.
     *
     * @param array<string, mixed> $fuel
     * @return list<int>
     */
    private function setFuelReadingStatus(array $fuel, string $fromStatus, string $toStatus, ?string $reason, Actor $actor): array
    {
        if ($fuel['odometer_reading_id'] === null) {
            return [];
        }

        return $this->readings->setStatusForFuel((int) $fuel['odometer_reading_id'], (int) $fuel['id'], $fromStatus, $toStatus, $reason, $actor);
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
    }

    private static function assertHuman(Actor $actor): void
    {
        if ($actor->via !== Actor::VIA_WEB) {
            throw new DomainError('forbidden', 'Löschen, Wiederherstellen und Fahrzeugstatus ändert nur ein Mensch in der Oberfläche.');
        }
    }

    private static function tableFor(string $type): string
    {
        return self::RECORD_TABLES[$type] ?? throw new DomainError('not_found', 'Diese Art von Eintrag gibt es nicht.', ['type' => $type, 'allowed' => array_keys(self::RECORD_TABLES)]);
    }

    /**
     * Sperrt das Fahrzeug (auch ein gelöschtes) und verlangt die Rolle Inhaber.
     *
     * @return array{id: int, garage_id: int, label: string, status: string, status_reason: string|null, updated_at: string, is_restorable: bool}
     */
    private function ownedVehicle(int $vehicleId, Actor $actor, string $forbiddenMessage): array
    {
        $vehicle = $this->vehicles->findForLifecycle($vehicleId, self::RESTORE_DAYS, $actor);
        if ($vehicle === null) {
            throw new DomainError('not_found', 'Ein Fahrzeug mit dieser ID gibt es in deinen Garagen nicht.', ['vehicle_id' => $vehicleId]);
        }
        if (($actor->roles[$vehicle['garage_id']] ?? null) !== Actor::ROLE_OWNER) {
            throw new DomainError('forbidden', $forbiddenMessage, ['garage_id' => $vehicle['garage_id']]);
        }

        return $vehicle;
    }

    /**
     * Sperrreihenfolge wie überall: erst das Fahrzeug (mit Schreibrecht), dann der Datensatz. Fremd, unbekannt oder an
     * einem gelöschten Fahrzeug ergibt `not_found`. Garagen-Verträge und ihre Vorgänge haben kein Fahrzeug: dort sperrt die
     * Garage, und es zählt das Schreibrecht in ihr.
     *
     * @return array{garage_id: int, vehicle_id: int|null, status: string, status_reason: string|null, row: array<string, mixed>}
     */
    private function lockedRecord(string $table, int $id, Actor $actor): array
    {
        $known = $this->loadRecord($table, $id, $actor, false);
        if ($known === null) {
            throw self::recordNotFound($id);
        }
        if ($known['vehicle_id'] === null) {
            if ($this->contracts->lockGarage($known['garage_id'], $actor) === null) {
                throw self::recordNotFound($id);
            }
            $this->access->assertCanWriteGarage($known['garage_id'], $actor);
        } else {
            $this->access->writable($known['vehicle_id'], $actor);
        }

        return $this->loadRecord($table, $id, $actor, true) ?? throw self::recordNotFound($id);
    }

    /** @return array{garage_id: int, vehicle_id: int|null, status: string, status_reason: string|null, row: array<string, mixed>}|null */
    private function loadRecord(string $table, int $id, Actor $actor, bool $lock): ?array
    {
        if ($table === self::NOTES_TABLE) {
            $note = $this->notes->findAnyStatus($id, $actor, $lock);
            if ($note === null) {
                return null;
            }

            return ['garage_id' => $note['garage_id'], 'vehicle_id' => $note['vehicle_id'], 'status' => $note['status'], 'status_reason' => null, 'row' => $note];
        }

        $row = isset(ContractMerger::TABLES[$table])
            ? $this->contracts->findAnyStatus($table, $id, $actor, $lock)
            : $this->records->findAnyStatus($table, $id, $actor, $lock);
        if ($row === null) {
            return null;
        }

        return [
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'status' => (string) $row['status'],
            'status_reason' => $row['status_reason'] === null ? null : (string) $row['status_reason'],
            'row' => $row,
        ];
    }

    /** @param array{garage_id: int, vehicle_id: int|null, status: string, status_reason: string|null, row: array<string, mixed>} $record */
    private function writeStatus(string $table, array $record, string $status, ?string $reason, Actor $actor): void
    {
        $id = (int) $record['row']['id'];
        if ($table === self::NOTES_TABLE) {
            $this->notes->setStatus($id, $record['garage_id'], $status);

            return;
        }
        if (isset(ContractMerger::TABLES[$table])) {
            $this->contracts->setStatus($table, $id, $status, $reason, $actor);

            return;
        }

        $this->records->setStatus($table, $id, $status, $reason, $actor);
    }

    /**
     * Vertragstabellen vergleichen innerhalb der Garage (Garagen-Verträge haben kein Fahrzeug), alle übrigen am Fahrzeug.
     *
     * @param array<string, mixed> $row
     */
    private function hasActiveDuplicate(string $table, int $id, array $row, Actor $actor): bool
    {
        if (isset(ContractMerger::TABLES[$table])) {
            return $this->contracts->hasActiveDuplicate($table, $id, $actor);
        }

        return $this->records->hasActiveDuplicate($table, $id, self::keyColumns($table, $row), $actor);
    }

    /**
     * Wie der Abgleich denselben Datensatz erkennt; Ereignisse ohne Rechnungsnummer über Art + Datum + Werkstatt (wie `EventService`).
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function keyColumns(string $table, array $row): array
    {
        if ($table === self::EVENTS_TABLE && $row['invoice_number'] === null) {
            return ['kind', 'performed_on', 'workshop_id'];
        }

        return RecordMerger::TABLES[$table]['key'];
    }

    private static function recordNotFound(int $id): DomainError
    {
        return new DomainError('not_found', 'Einen solchen Eintrag gibt es in deinen Garagen nicht.', ['id' => $id]);
    }

    /** @param array{id: int, garage_id: int, status: string, status_reason: string|null} $vehicle */
    private function recordVehicleAudit(Actor $actor, string $action, array $vehicle, string $status, ?string $reason): void
    {
        $this->audit->record(
            $actor,
            $action,
            sprintf('vehicles:%d', $vehicle['id']),
            $vehicle['garage_id'],
            ['status' => $vehicle['status'], 'status_reason' => $vehicle['status_reason']],
            ['status' => $status, 'status_reason' => $reason],
            $vehicle['id']
        );
    }

    /**
     * @param array{garage_id: int, vehicle_id: int|null, status: string, status_reason: string|null, row: array<string, mixed>} $record
     * @param array<string, mixed> $after
     */
    private function recordRecordAudit(Actor $actor, string $action, string $table, array $record, array $after): void
    {
        $this->audit->record(
            $actor,
            $action,
            sprintf('%s:%d', $table, (int) $record['row']['id']),
            $record['garage_id'],
            ['status' => $record['status'], 'status_reason' => $record['status_reason']],
            $after,
            $record['vehicle_id']
        );
    }

    private static function restorableUntil(string $deletedAt): string
    {
        return (new DateTimeImmutable($deletedAt, new DateTimeZone('UTC')))
            ->modify('+' . self::RESTORE_DAYS . ' days')
            ->format('Y-m-d\TH:i:s\Z');
    }
}
