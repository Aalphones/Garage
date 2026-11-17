<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Schäden und Rückrufe eines Fahrzeugs über den Datensatz-Abgleich. */
final class DamageService
{
    public const DAMAGE_KINDS = ['unfall', 'hagel', 'lack', 'glas', 'vandalismus', 'sonstiges'];
    public const RECALL_STATUSES = ['offen', 'erledigt', 'nicht_betroffen'];

    private const DAMAGE_EXAMPLE = '{"occurred_on": "2025-08-14", "kind": "hagel", "description": "Hagelschaden an Dach und Motorhaube", "repaired": false}';
    private const RECALL_EXAMPLE = '{"title": "Rückruf Hochvoltbatterie", "kba_reference": "012345", "status": "offen"}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;
    private EventLink $eventLink;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
        $this->eventLink = new EventLink($pdo);
    }

    /**
     * Datum und Art erkennen denselben Schaden wieder.
     *
     * @param array<string, mixed> $input occurred_on, kind, description, odometer_km?, repaired?, repair_event_id?, appraisal?, diminished_value_eur?, source, source_url?, confidence
     * @return array{damage_id: int, result: string, open_item_ids: list<int>}
     */
    public function recordDamage(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $values = [
                'occurred_on' => Validate::date('occurred_on', $input['occurred_on'] ?? null, self::DAMAGE_EXAMPLE),
                'kind' => Validate::enum('kind', $input['kind'] ?? null, self::DAMAGE_KINDS),
                'description' => Validate::text('description', $input['description'] ?? null, 1, 500, self::DAMAGE_EXAMPLE),
                'odometer_km' => ($input['odometer_km'] ?? null) === null ? null : OdometerService::validKm('odometer_km', $input['odometer_km']),
                'repaired' => Validate::bool('repaired', $input['repaired'] ?? null),
                'repair_event_id' => $this->eventLink->validated('repair_event_id', $input['repair_event_id'] ?? null, $vehicleId, $actor),
                'appraisal' => Validate::bool('appraisal', $input['appraisal'] ?? null),
                'diminished_value_cents' => Validate::euroToCents('diminished_value_eur', $input['diminished_value_eur'] ?? null),
            ];
            $provenance = Provenance::validate($input, 'damage');
            $merged = $this->merger->merge('damages', $values, $provenance, $vehicle, $actor);

            return ['damage_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Herstellernummer und KBA-Referenz erkennen denselben Rückruf wieder; mindestens eine von beiden ist Pflicht.
     *
     * @param array<string, mixed> $input title, manufacturer_code?, kba_reference?, status, done_on?, event_id?, source, source_url?, confidence
     * @return array{recall_id: int, result: string, open_item_ids: list<int>}
     */
    public function recordRecall(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $manufacturerCode = Validate::optionalText('manufacturer_code', $input['manufacturer_code'] ?? null, 1, 40, '{"manufacturer_code": "23Y5"}') ?? '';
            $kbaReference = Validate::optionalText('kba_reference', $input['kba_reference'] ?? null, 1, 40, '{"kba_reference": "012345"}') ?? '';
            if ($manufacturerCode === '' && $kbaReference === '') {
                throw Validate::invalid('kba_reference', 'Ein Rückruf braucht kba_reference oder manufacturer_code, sonst ist er nicht wiederzuerkennen.', self::RECALL_EXAMPLE);
            }
            $values = [
                'manufacturer_code' => $manufacturerCode,
                'kba_reference' => $kbaReference,
                'title' => Validate::text('title', $input['title'] ?? null, 1, 200, self::RECALL_EXAMPLE),
                'recall_status' => Validate::enum('status', $input['status'] ?? null, self::RECALL_STATUSES),
                'done_on' => ($input['done_on'] ?? null) === null ? null : Validate::date('done_on', $input['done_on'], '{"done_on": "2026-02-10"}'),
                'event_id' => $this->eventLink->validated('event_id', $input['event_id'] ?? null, $vehicleId, $actor),
            ];
            $provenance = Provenance::validate($input, 'recall');
            $merged = $this->merger->merge('recalls', $values, $provenance, $vehicle, $actor);

            return ['recall_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function listDamages(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        $damages = [];
        foreach ($this->records->listForVehicle('damages', $vehicleId, $actor) as $row) {
            $damages[] = [
                'id' => (int) $row['id'],
                'occurred_on' => (string) $row['occurred_on'],
                'kind' => (string) $row['kind'],
                'description' => (string) $row['description'],
                'odometer_km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
                'repaired' => $row['repaired'] === null ? null : (bool) $row['repaired'],
                'repair_event_id' => $row['repair_event_id'] === null ? null : (int) $row['repair_event_id'],
                'appraisal' => $row['appraisal'] === null ? null : (bool) $row['appraisal'],
                'diminished_value_eur' => $row['diminished_value_cents'] === null ? null : Money::centsToEuro((int) $row['diminished_value_cents']),
                'source' => (string) $row['source'],
                'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $damages;
    }

    /**
     * Offene zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function listRecalls(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        $recalls = [];
        foreach ($this->records->listForVehicle('recalls', $vehicleId, $actor) as $row) {
            $recalls[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'manufacturer_code' => (string) $row['manufacturer_code'] === '' ? null : (string) $row['manufacturer_code'],
                'kba_reference' => (string) $row['kba_reference'] === '' ? null : (string) $row['kba_reference'],
                'status' => (string) $row['recall_status'],
                'done_on' => $row['done_on'] === null ? null : (string) $row['done_on'],
                'event_id' => $row['event_id'] === null ? null : (int) $row['event_id'],
                'source' => (string) $row['source'],
                'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $recalls;
    }
}
