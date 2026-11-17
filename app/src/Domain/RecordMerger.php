<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Datensatz-Abgleich für alle Listen-Tabellen (ADR-009): derselbe Datensatz — erkannt am natürlichen Schlüssel — wird
 * nie doppelt angelegt. Leere Spalten werden ergänzt, nicht bestätigte überschrieben, bestätigte nie: eine Abweichung
 * wird ein Klärfall mit gespeichertem Vorschlag.
 */
final class RecordMerger
{
    public const RESULT_CREATED = 'angelegt';
    public const RESULT_SUPPLEMENTED = 'ergaenzt';
    public const RESULT_UPDATED = 'aktualisiert';
    public const RESULT_UNCHANGED = 'unveraendert';
    public const RESULT_DISPUTE = 'klaerfall';

    /**
     * Einzige Quelle für Tabellen- und Spaltennamen im dynamischen SQL; Namen aus der Eingabe kommen nie hinein.
     * `key` erkennt denselben Datensatz wieder, `columns` sind die abgleichbaren Angaben.
     */
    public const TABLES = [
        'registrations' => ['label' => 'Zulassung', 'key' => ['plate', 'valid_from'], 'columns' => ['valid_to', 'holder', 'holder_address']],
        'equipment' => ['label' => 'Ausstattung', 'key' => ['name'], 'columns' => ['code', 'category', 'list_price_cents']],
        // Mit Rechnungsnummer erkennt Werkstatt + Nummer das Ereignis; ohne sie übergibt EventService den Schlüssel Art + Datum + Werkstatt.
        'events' => [
            'label' => 'Ereignis',
            'key' => ['workshop_id', 'invoice_number'],
            'columns' => ['kind', 'performed_on', 'title', 'invoiced_on', 'odometer_km', 'order_number', 'net_cents', 'vat_cents', 'gross_cents', 'digital_service_record'],
        ],
        // Bei `sonstiges` gehört `label` zum Schlüssel, sonst ist es ''.
        'measurements' => ['label' => 'Messwert', 'key' => ['measured_on', 'quantity', 'position', 'label'], 'columns' => ['value', 'unit', 'odometer_km', 'note', 'event_id']],
        'tire_sets' => ['label' => 'Reifensatz', 'key' => ['label'], 'columns' => ['brand', 'model', 'size', 'season', 'dot', 'storage_location']],
        // Leere Marke und Artikelnummer stehen als '' im Schlüssel (NULL würde nie wiedererkannt).
        'parts' => ['label' => 'Teil', 'key' => ['component', 'brand', 'article_number'], 'columns' => ['oem_number', 'role', 'verified', 'note']],
        'damages' => ['label' => 'Schaden', 'key' => ['occurred_on', 'kind'], 'columns' => ['description', 'odometer_km', 'repaired', 'repair_event_id', 'appraisal', 'diminished_value_cents']],
        'recalls' => ['label' => 'Rückruf', 'key' => ['manufacturer_code', 'kba_reference'], 'columns' => ['title', 'recall_status', 'done_on', 'event_id']],
    ];

    private OpenItemRepository $openItems;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Der Aufrufer hat das Fahrzeug über `VehicleAccess::writable` gesperrt; der Abgleich läuft in dessen Transaktion.
     *
     * @param array<string, string|null> $values Spalte → normalisierter Text; `null` heißt „nicht angegeben“. Schlüsselspalten gehören dazu.
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param list<string>|null $key überschreibt den Schlüssel der Tabelle
     * @return array{id: int, result: string, open_item_ids: list<int>, changed: list<string>}
     */
    public function merge(string $table, array $values, array $provenance, array $vehicle, Actor $actor, ?array $key = null): array
    {
        $definition = self::TABLES[$table] ?? throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht für den Abgleich vorgesehen.', $table));
        if (!$actor->canRead($vehicle['garage_id'])) {
            throw new LogicException('Abgleich in einer fremden Garage.');
        }
        $keyColumns = $key ?? $definition['key'];
        $compareColumns = array_values(array_diff($definition['columns'], $keyColumns));
        $this->assertKnownColumns($table, $values, $keyColumns, $compareColumns);

        return Transaction::join($this->pdo, function () use ($table, $definition, $values, $provenance, $vehicle, $actor, $keyColumns, $compareColumns): array {
            $existing = $this->find($table, $vehicle, $keyColumns, $compareColumns, $values, $actor);
            if ($existing === null) {
                return $this->insert($table, $values, $provenance, $vehicle, $actor);
            }

            return $this->reconcile($table, $definition['label'], $existing, $compareColumns, $values, $provenance, $vehicle, $actor);
        });
    }

    /**
     * „Neuer Wert gilt“: setzt die Spalte aus dem Vorschlag eines Klärfalls und die Herkunft der Zeile auf die des Vorschlags.
     * Der Aufrufer (`OpenItemService::resolve`) hat das Fahrzeug gesperrt und schreibt den Audit-Eintrag.
     *
     * @param array<string, mixed> $proposal field, value, source, source_url, confidence
     * @return array{before: array<string, string|null>, after: array<string, string|null>}
     */
    public function applyProposal(string $table, int $rowId, array $proposal, Actor $actor): array
    {
        $definition = self::TABLES[$table] ?? throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht für den Abgleich vorgesehen.', $table));
        $field = $proposal['field'] ?? null;
        $value = $proposal['value'] ?? null;
        if (!is_string($field) || !in_array($field, $definition['columns'], true) || !is_string($value)) {
            throw new LogicException(sprintf('Der Vorschlag passt nicht zu %s: Spalte „%s“ ist dort nicht abgleichbar.', $table, is_string($field) ? $field : '(fehlt)'));
        }
        $provenance = Provenance::validate($proposal, 'proposal');

        $garageIds = $actor->garageIds();
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT %s AS stored, source, source_url, confidence FROM %s WHERE id = ? AND status = 'aktiv' AND garage_id IN (%s) FOR UPDATE",
                $field,
                $table,
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$rowId, ...$garageIds]);
        $existing = $statement->fetch();
        if ($existing === false) {
            throw new DomainError('not_found', sprintf('Der Datensatz (%s) zu diesem Klärfall existiert nicht mehr.', $definition['label']));
        }

        $update = $this->pdo->prepare(
            sprintf('UPDATE %s SET %s = ?, source = ?, source_url = ?, confidence = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id IN (%s)', $table, $field, implode(', ', array_fill(0, count($garageIds), '?')))
        );
        $update->execute([$value, $provenance['source'], $provenance['source_url'], $provenance['confidence'], $rowId, ...$garageIds]);

        return [
            'before' => [$field => $existing['stored'] === null ? null : (string) $existing['stored'], 'source' => (string) $existing['source'], 'confidence' => (string) $existing['confidence']],
            'after' => [$field => $value, 'source' => $provenance['source'], 'confidence' => $provenance['confidence']],
        ];
    }

    /**
     * @param array<string, string|null> $values
     * @param list<string> $keyColumns
     * @param list<string> $compareColumns
     */
    private function assertKnownColumns(string $table, array $values, array $keyColumns, array $compareColumns): void
    {
        foreach ($keyColumns as $column) {
            // `null` ist als Schlüsselwert erlaubt (Ereignis ohne Werkstatt); die Suche vergleicht mit `<=>`.
            if (!array_key_exists($column, $values)) {
                throw new LogicException(sprintf('Schlüsselspalte „%s“ von %s fehlt.', $column, $table));
            }
        }
        foreach (array_keys($values) as $column) {
            if (!in_array($column, $keyColumns, true) && !in_array($column, $compareColumns, true)) {
                throw new LogicException(sprintf('Spalte „%s“ gehört nicht zu %s.', $column, $table));
            }
        }
    }

    /**
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param list<string> $keyColumns
     * @param list<string> $compareColumns
     * @param array<string, string|null> $values
     * @return array<string, mixed>|null
     */
    private function find(string $table, array $vehicle, array $keyColumns, array $compareColumns, array $values, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        $keyConditions = array_map(static fn (string $column): string => $column . ' <=> ?', $keyColumns);
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, %s, source, source_url, confidence FROM %s
                 WHERE vehicle_id = ? AND garage_id IN (%s) AND status = 'aktiv' AND %s
                 ORDER BY id LIMIT 1 FOR UPDATE",
                implode(', ', [...$keyColumns, ...$compareColumns]),
                $table,
                implode(', ', array_fill(0, count($garageIds), '?')),
                implode(' AND ', $keyConditions)
            )
        );
        $statement->execute([$vehicle['id'], ...$garageIds, ...array_map(static fn (string $column): ?string => $values[$column], $keyColumns)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string|null> $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @return array{id: int, result: string, open_item_ids: list<int>, changed: list<string>}
     */
    private function insert(string $table, array $values, array $provenance, array $vehicle, Actor $actor): array
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %s (garage_id, vehicle_id, %s, source, source_url, confidence, actor, user_id, created_at, updated_at)
                 VALUES (?, ?, %s, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            )
        );
        $statement->execute([
            $vehicle['garage_id'],
            $vehicle['id'],
            ...array_values($values),
            $provenance['source'],
            $provenance['source_url'],
            $provenance['confidence'],
            $actor->name,
            $actor->userId,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit->record($actor, $table . '.create', sprintf('%s:%d', $table, $id), $vehicle['garage_id'], null, array_filter($values, static fn (?string $value): bool => $value !== null), $vehicle['id']);

        return ['id' => $id, 'result' => self::RESULT_CREATED, 'open_item_ids' => [], 'changed' => array_keys(array_filter($values, static fn (?string $value): bool => $value !== null))];
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string> $compareColumns
     * @param array<string, string|null> $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @return array{id: int, result: string, open_item_ids: list<int>, changed: list<string>}
     */
    private function reconcile(string $table, string $label, array $existing, array $compareColumns, array $values, array $provenance, array $vehicle, Actor $actor): array
    {
        $id = (int) $existing['id'];
        $isConfirmed = $existing['confidence'] === Provenance::CONFIRMED;
        $before = [];
        $after = [];
        $hasSupplemented = false;
        $hasReplaced = false;
        $disputes = [];

        foreach ($compareColumns as $column) {
            $incoming = $values[$column] ?? null;
            if ($incoming === null) {
                continue;
            }
            // Datenbankwerte kommen je nach Spaltentyp als Zahl oder Text; verglichen wird als Text.
            $stored = $existing[$column] === null ? null : (string) $existing[$column];
            if ($stored === $incoming) {
                continue;
            }
            if ($stored === null) {
                $after[$column] = $incoming;
                $hasSupplemented = true;
            } elseif (!$isConfirmed) {
                $before[$column] = $stored;
                $after[$column] = $incoming;
                $hasReplaced = true;
            } else {
                $disputes[$column] = ['stored' => $stored, 'incoming' => $incoming];
            }
        }

        $isHigher = Provenance::isHigher($provenance['confidence'], (string) $existing['confidence']);
        $changed = array_keys($after);
        if ($after !== [] || $isHigher) {
            $this->update($table, $id, $after, $provenance, $isHigher, $existing, $vehicle, $actor, $before);
            if ($isHigher) {
                $changed[] = 'confidence';
            }
        }

        $openItemIds = [];
        foreach ($disputes as $column => $dispute) {
            $openItemIds[] = $this->recordDispute($table, $label, $id, $column, $dispute, $provenance, $vehicle, $actor);
        }

        return ['id' => $id, 'result' => self::outcome($disputes !== [], $hasReplaced || $isHigher, $hasSupplemented), 'open_item_ids' => $openItemIds, 'changed' => $changed];
    }

    private static function outcome(bool $hasDispute, bool $hasUpdate, bool $hasSupplement): string
    {
        return match (true) {
            $hasDispute => self::RESULT_DISPUTE,
            $hasUpdate => self::RESULT_UPDATED,
            $hasSupplement => self::RESULT_SUPPLEMENTED,
            default => self::RESULT_UNCHANGED,
        };
    }

    /**
     * Ändert Spalten und Herkunft in einer Anweisung. Wird eine Spalte gesetzt, gilt ab da die neue Herkunft für die Zeile;
     * die Konfidenz sinkt dabei nie.
     *
     * @param array<string, string> $after
     * @param array<string, string> $before
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array<string, mixed> $existing
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     */
    private function update(string $table, int $id, array $after, array $provenance, bool $isHigher, array $existing, array $vehicle, Actor $actor, array $before): void
    {
        $assignments = array_map(static fn (string $column): string => $column . ' = ?', array_keys($after));
        $parameters = array_values($after);
        $assignments = [...$assignments, 'source = ?', 'source_url = ?', 'confidence = ?', 'updated_at = UTC_TIMESTAMP()'];
        $parameters = [...$parameters, $provenance['source'], $provenance['source_url'], $isHigher ? $provenance['confidence'] : $existing['confidence']];

        $statement = $this->pdo->prepare(sprintf('UPDATE %s SET %s WHERE id = ? AND garage_id = ?', $table, implode(', ', $assignments)));
        $statement->execute([...$parameters, $id, $vehicle['garage_id']]);

        $auditBefore = $before;
        $auditAfter = $after;
        if ($isHigher) {
            $auditBefore['confidence'] = (string) $existing['confidence'];
            $auditAfter['confidence'] = $provenance['confidence'];
        }
        $this->audit->record($actor, $table . '.update', sprintf('%s:%d', $table, $id), $vehicle['garage_id'], $auditBefore === [] ? null : $auditBefore, $auditAfter, $vehicle['id']);
    }

    /**
     * Gleicher Widerspruch zum zweiten Mal: bestehender Klärfall, kein neuer.
     *
     * @param array{stored: string, incoming: string} $dispute
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     */
    private function recordDispute(string $table, string $label, int $id, string $column, array $dispute, array $provenance, array $vehicle, Actor $actor): int
    {
        foreach ($this->openItems->findOpenByRef($table, $id, $actor) as $openItem) {
            $proposal = $openItem['proposal'];
            if ($proposal !== null && ($proposal['field'] ?? null) === $column && ($proposal['value'] ?? null) === $dispute['incoming']) {
                return $openItem['id'];
            }
        }

        $proposal = ['field' => $column, 'value' => $dispute['incoming'], 'source' => $provenance['source'], 'source_url' => $provenance['source_url'], 'confidence' => $provenance['confidence']];
        $text = sprintf(
            'Widerspruch bei „%s“ (%s) am Fahrzeug „%s“: bestätigt ist „%s“, neu gemeldet wird „%s“ (Quelle: %s, Konfidenz %s). Ein Mensch entscheidet, welcher Wert gilt.',
            $column,
            $label,
            $vehicle['label'],
            $dispute['stored'],
            $dispute['incoming'],
            $provenance['source'],
            $provenance['confidence']
        );
        $openItemId = $this->openItems->insert($vehicle['garage_id'], $vehicle['id'], OpenItemRepository::KIND_CONFLICT, $text, $table, $id, $actor, $proposal);
        $this->audit->record($actor, $table . '.dispute', sprintf('%s:%d', $table, $id), $vehicle['garage_id'], [$column => $dispute['stored']], $proposal + ['open_item_id' => $openItemId], $vehicle['id']);

        return $openItemId;
    }
}
