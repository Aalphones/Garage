<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Garage\Db\Transaction;
use PDO;

/** Reifensätze eines Fahrzeugs (Datensatz-Abgleich) und ihre Montagen: ein Satz ist montiert oder eingelagert. */
final class TireService
{
    public const ACTION_MOUNTED = 'montiert';
    public const ACTION_STORED = 'eingelagert';
    public const ACTION_UNCHANGED = 'unveraendert';

    private const TABLE = 'tire_sets';
    private const SEASONS = ['sommer', 'winter', 'ganzjahr'];
    private const EXAMPLE = '{"set": {"label": "Sommer 16 Zoll", "size": "205/55 R16 91V", "season": "sommer", "dot": "2123"}, "mount": {"on": "2026-04-01", "km": 27500}}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;
    private TireRepository $mounts;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
        $this->mounts = new TireRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Legt den Satz an oder gleicht ihn ab und montiert oder lagert ihn danach ein. Montieren schließt die offene Montage
     * eines anderen Satzes am selben Tag; dieselbe Montage noch einmal ändert nichts.
     *
     * @param array<string, mixed> $input set, mount?, unmount?, source, source_url?, confidence
     * @return array{tire_set_id: int, result: string, open_item_ids: list<int>, mount: array{mount_id: int, action: string}|null}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $values = self::validSet($input['set'] ?? null);
            $mount = self::validMovement('mount', $input['mount'] ?? null);
            $unmount = self::validMovement('unmount', $input['unmount'] ?? null);
            if ($mount !== null && $unmount !== null) {
                throw new DomainError('invalid_argument', 'mount und unmount gehören nicht in denselben Aufruf. Montieren schließt die vorige Montage ohnehin; zum Einlagern genügt unmount allein.', ['field' => 'unmount']);
            }
            $provenance = Provenance::validate($input, 'tire_set');

            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);
            $movement = null;
            if ($mount !== null) {
                $movement = $this->mount($vehicle, $merged['id'], $mount, $actor);
            } elseif ($unmount !== null) {
                $movement = $this->unmount($vehicle, $merged['id'], $unmount, $actor);
            }

            return ['tire_set_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids'], 'mount' => $movement];
        });
    }

    /**
     * @return array{mounted_set_id: int|null, sets: list<array<string, mixed>>}
     */
    public function list(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);
        $rows = $this->records->listForVehicle(self::TABLE, $vehicleId, $actor);
        $mountsBySet = [];
        foreach ($this->mounts->forSets(array_map(static fn (array $row): int => (int) $row['id'], $rows), $actor) as $mount) {
            $mountsBySet[$mount['tire_set_id']][] = $mount;
        }
        $today = $this->today();

        $mountedSetId = null;
        $sets = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $mounts = [];
            foreach ($mountsBySet[$id] ?? [] as $mount) {
                $mounts[] = ['mounted_on' => $mount['mounted_on'], 'mounted_km' => $mount['mounted_km'], 'removed_on' => $mount['removed_on'], 'removed_km' => $mount['removed_km']];
                if ($mount['removed_on'] === null) {
                    $mountedSetId = $id;
                }
            }
            $dot = $row['dot'] === null ? null : (string) $row['dot'];
            $sets[] = [
                'id' => $id,
                'label' => (string) $row['label'],
                'brand' => $row['brand'] === null ? null : (string) $row['brand'],
                'model' => $row['model'] === null ? null : (string) $row['model'],
                'size' => (string) $row['size'],
                'season' => (string) $row['season'],
                'dot' => $dot,
                'dot_age_years' => $dot === null ? null : self::dotAgeYears($dot, $today),
                'storage_location' => $row['storage_location'] === null ? null : (string) $row['storage_location'],
                'mounts' => $mounts,
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return ['mounted_set_id' => $mountedSetId, 'sets' => $sets];
    }

    /**
     * Volle Jahre zwischen dem Montag der DOT-Woche (`WWYY`) und heute; 0 für ein Datum in der Zukunft.
     */
    public static function dotAgeYears(string $dot, string $today): int
    {
        $week = (int) substr($dot, 0, 2);
        $year = 2000 + (int) substr($dot, 2, 2);
        $utc = new DateTimeZone('UTC');
        $produced = (new DateTimeImmutable('today', $utc))->setISODate($year, $week, 1);
        $now = new DateTimeImmutable($today, $utc);
        if ($produced > $now) {
            return 0;
        }

        return $produced->diff($now)->y;
    }

    /**
     * @param array{on: string, km: string|null} $movement
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @return array{mount_id: int, action: string}
     */
    private function mount(array $vehicle, int $tireSetId, array $movement, Actor $actor): array
    {
        $existing = $this->mounts->findMountedOn($tireSetId, $movement['on'], $actor);
        if ($existing !== null) {
            if ($movement['km'] !== null && $existing['mounted_km'] === null) {
                $this->mounts->fillMountedKm($existing['id'], $vehicle['garage_id'], $movement['km']);
                $this->audit->record($actor, 'tire_mount.update', sprintf('tire_mounts:%d', $existing['id']), $vehicle['garage_id'], null, ['mounted_km' => $movement['km']], $vehicle['id']);
            }

            return ['mount_id' => $existing['id'], 'action' => self::ACTION_UNCHANGED];
        }

        $open = $this->mounts->openForVehicle($vehicle['id'], $actor);
        foreach ($open as $openMount) {
            if ($openMount['tire_set_id'] === $tireSetId) {
                throw Validate::invalid('mount.on', sprintf('Der Satz ist seit %s montiert. Erst mit unmount einlagern oder dasselbe Datum verwenden.', $openMount['mounted_on']), '{"mount": {"on": "' . $openMount['mounted_on'] . '"}}');
            }
            if ($movement['on'] < $openMount['mounted_on']) {
                throw Validate::invalid('mount.on', sprintf('Am Fahrzeug ist seit %s ein anderer Satz montiert; die neue Montage kann nicht früher liegen.', $openMount['mounted_on']), '{"mount": {"on": "' . $openMount['mounted_on'] . '"}}');
            }
        }
        foreach ($open as $openMount) {
            $this->closeMount($vehicle, $openMount, $movement, $actor);
        }

        $mountId = $this->mounts->insert($vehicle['garage_id'], $vehicle['id'], $tireSetId, $movement['on'], $movement['km'], $actor);
        $this->audit->record($actor, 'tire_mount.create', sprintf('tire_mounts:%d', $mountId), $vehicle['garage_id'], null, ['tire_set_id' => $tireSetId, 'mounted_on' => $movement['on'], 'mounted_km' => $movement['km']], $vehicle['id']);

        return ['mount_id' => $mountId, 'action' => self::ACTION_MOUNTED];
    }

    /**
     * @param array{on: string, km: string|null} $movement
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @return array{mount_id: int, action: string}
     */
    private function unmount(array $vehicle, int $tireSetId, array $movement, Actor $actor): array
    {
        $open = null;
        foreach ($this->mounts->openForVehicle($vehicle['id'], $actor) as $openMount) {
            if ($openMount['tire_set_id'] === $tireSetId) {
                $open = $openMount;
            }
        }
        if ($open === null) {
            $removed = $this->mounts->findRemovedOn($tireSetId, $movement['on'], $actor);
            if ($removed !== null) {
                return ['mount_id' => $removed['id'], 'action' => self::ACTION_UNCHANGED];
            }

            throw Validate::invalid('unmount', 'Der Satz ist nicht montiert, es gibt nichts auszubauen. get_tires zeigt, welcher Satz montiert ist.', '{"unmount": {"on": "2026-10-15"}}');
        }
        if ($movement['on'] < $open['mounted_on']) {
            throw Validate::invalid('unmount.on', sprintf('Der Ausbau kann nicht vor der Montage am %s liegen.', $open['mounted_on']), '{"unmount": {"on": "' . $open['mounted_on'] . '"}}');
        }
        $this->closeMount($vehicle, $open, $movement, $actor);

        return ['mount_id' => $open['id'], 'action' => self::ACTION_STORED];
    }

    /**
     * @param array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null} $openMount
     * @param array{on: string, km: string|null} $movement
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     */
    private function closeMount(array $vehicle, array $openMount, array $movement, Actor $actor): void
    {
        $this->mounts->close($openMount['id'], $vehicle['garage_id'], $movement['on'], $movement['km']);
        $this->audit->record($actor, 'tire_mount.update', sprintf('tire_mounts:%d', $openMount['id']), $vehicle['garage_id'], null, ['removed_on' => $movement['on'], 'removed_km' => $movement['km']], $vehicle['id']);
    }

    private function today(): string
    {
        return (string) $this->pdo->query('SELECT UTC_DATE()')->fetchColumn();
    }

    /**
     * @return array<string, string|null>
     */
    private static function validSet(mixed $raw): array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw Validate::invalid('set', 'Der Reifensatz ist ein Objekt mit label, size und season.', self::EXAMPLE);
        }

        return [
            'label' => Validate::text('set.label', $raw['label'] ?? null, 1, 80, self::EXAMPLE),
            'brand' => Validate::optionalText('set.brand', $raw['brand'] ?? null, 1, 60, '{"brand": "Michelin"}'),
            'model' => Validate::optionalText('set.model', $raw['model'] ?? null, 1, 80, '{"model": "Primacy 4"}'),
            'size' => Validate::text('set.size', $raw['size'] ?? null, 1, 30, '{"size": "205/55 R16 91V"}'),
            'season' => Validate::enum('set.season', $raw['season'] ?? null, self::SEASONS),
            'dot' => self::validDot($raw['dot'] ?? null),
            'storage_location' => Validate::optionalText('set.storage_location', $raw['storage_location'] ?? null, 1, 120, '{"storage_location": "Keller, Regal 2"}'),
        ];
    }

    /** `WWYY`: Produktionswoche 01–53 und zweistelliges Jahr. */
    private static function validDot(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $example = '{"dot": "2123"}';
        $dot = Validate::text('set.dot', $raw, 4, 4, $example);
        if (preg_match('/^[0-9]{4}$/', $dot) !== 1 || (int) substr($dot, 0, 2) < 1 || (int) substr($dot, 0, 2) > 53) {
            throw Validate::invalid('set.dot', 'Der DOT-Code hat vier Ziffern: Produktionswoche 01 bis 53, dann das Jahr (2123 = Woche 21 in 2023).', $example);
        }

        return $dot;
    }

    /** @return array{on: string, km: string|null}|null */
    private static function validMovement(string $field, mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $example = sprintf('{"%s": {"on": "2026-04-01", "km": 27500}}', $field);
        if (!is_array($raw) || array_is_list($raw)) {
            throw Validate::invalid($field, 'Erwartet wird ein Objekt mit on (Datum) und optional km.', $example);
        }

        return [
            'on' => Validate::date($field . '.on', $raw['on'] ?? null, $example),
            'km' => ($raw['km'] ?? null) === null ? null : OdometerService::validKm($field . '.km', $raw['km']),
        ];
    }
}
