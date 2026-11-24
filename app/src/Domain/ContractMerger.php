<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Datensatz-Abgleich der Vertragstabellen (ADR-022): dieselben Regeln und Ergebniswörter wie `RecordMerger`, aber der
 * Bereich ist die Garage statt des Fahrzeugs, weil Garagen-Verträge (ADAC) kein Fahrzeug haben. Ein Fahrzeug-Vertrag trägt
 * `vehicle_id` im Schlüssel; Perioden, Anteile und Vorgänge hängen über `contract_id` am Vertrag.
 */
final class ContractMerger
{
    /**
     * Einzige Quelle für Tabellen- und Spaltennamen im dynamischen SQL; Namen aus der Eingabe kommen nie hinein.
     * Leere Vertragsnummer steht als '' im Schlüssel (NULL würde nie wiedererkannt).
     */
    public const TABLES = [
        'contracts' => [
            'label' => 'Vertrag',
            'key' => ['vehicle_id', 'kind', 'provider', 'contract_number'],
            'columns' => ['tariff', 'starts_on', 'main_due', 'notice_months', 'payment_interval', 'ends_on', 'allocation', 'hotline', 'member_number', 'note'],
        ],
        'contract_terms' => [
            'label' => 'Beitragsperiode',
            'key' => ['contract_id', 'valid_from'],
            'columns' => ['valid_to', 'amount_cents', 'coverage', 'sf_liability', 'sf_comprehensive', 'type_class_liability', 'type_class_partial', 'type_class_full', 'regional_class', 'declared_km_per_year', 'deductible_note'],
        ],
        'contract_allocations' => ['label' => 'Umlage-Anteil', 'key' => ['contract_id', 'vehicle_id', 'valid_from'], 'columns' => ['share_percent', 'valid_to']],
        'contract_events' => ['label' => 'Vorgang', 'key' => ['contract_id', 'kind', 'occurred_on'], 'columns' => ['amount_cents', 'event_status', 'claim_number', 'damage_id', 'note']],
    ];

    private OpenItemRepository $openItems;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Der Aufrufer hat das Fahrzeug (`VehicleAccess::writable`) bzw. die Garage (`ContractRepository::lockGarage`) gesperrt
     * und das Schreibrecht geprüft; der Abgleich läuft in dessen Transaktion.
     *
     * @param array<string, string|null> $values Spalte → normalisierter Text; `null` heißt „nicht angegeben“. Schlüsselspalten gehören dazu.
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope `label` nennt den Ort im Klärfall-Text, z. B. „am Fahrzeug „Golf““
     * @param array<string, string> $insertDefaults Werte nur für eine neu angelegte Zeile, nie für den Abgleich
     * @return array{id: int, result: string, open_item_ids: list<int>}
     */
    public function merge(string $table, array $values, array $provenance, array $scope, Actor $actor, array $insertDefaults = []): array
    {
        $definition = self::TABLES[$table] ?? throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht für den Vertrags-Abgleich vorgesehen.', $table));
        if (!$actor->canRead($scope['garage_id'])) {
            throw new LogicException('Abgleich in einer fremden Garage.');
        }
        $this->assertKnownColumns($table, $values, $definition);

        return Transaction::join($this->pdo, function () use ($table, $definition, $values, $provenance, $scope, $actor, $insertDefaults): array {
            $existing = $this->find($table, $definition, $values, $scope);
            if ($existing === null) {
                foreach ($insertDefaults as $column => $default) {
                    $values[$column] ??= $default;
                }

                return $this->insert($table, $values, $provenance, $scope, $actor);
            }

            return $this->reconcile($table, $definition, $existing, $values, $provenance, $scope, $actor);
        });
    }

    /**
     * „Neuer Wert gilt“ für einen Klärfall an einer Vertragszeile; Gegenstück zu `RecordMerger::applyProposal`.
     *
     * @param array<string, mixed> $proposal field, value, source, source_url, confidence
     * @return array{before: array<string, string|null>, after: array<string, string|null>}
     */
    public function applyProposal(string $table, int $rowId, array $proposal, Actor $actor): array
    {
        $definition = self::TABLES[$table] ?? throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht für den Vertrags-Abgleich vorgesehen.', $table));
        $field = $proposal['field'] ?? null;
        $value = $proposal['value'] ?? null;
        if (!is_string($field) || !in_array($field, $definition['columns'], true) || !is_string($value)) {
            throw new LogicException(sprintf('Der Vorschlag passt nicht zu %s: Spalte „%s“ ist dort nicht abgleichbar.', $table, is_string($field) ? $field : '(fehlt)'));
        }
        $provenance = Provenance::validate($proposal, 'proposal');

        $garageIds = $actor->garageIds();
        $placeholders = implode(', ', array_fill(0, count($garageIds), '?'));
        $statement = $this->pdo->prepare(
            sprintf("SELECT %s AS old_value, source, source_url, confidence FROM %s WHERE id = ? AND status = 'aktiv' AND garage_id IN (%s) FOR UPDATE", $field, $table, $placeholders)
        );
        $statement->execute([$rowId, ...$garageIds]);
        $existing = $statement->fetch();
        if ($existing === false) {
            throw new DomainError('not_found', sprintf('Der Datensatz (%s) zu diesem Klärfall existiert nicht mehr.', $definition['label']));
        }

        $update = $this->pdo->prepare(
            sprintf('UPDATE %s SET %s = ?, source = ?, source_url = ?, confidence = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id IN (%s)', $table, $field, $placeholders)
        );
        $update->execute([$value, $provenance['source'], $provenance['source_url'], $provenance['confidence'], $rowId, ...$garageIds]);

        return [
            'before' => [$field => $existing['old_value'] === null ? null : (string) $existing['old_value'], 'source' => (string) $existing['source'], 'confidence' => (string) $existing['confidence']],
            'after' => [$field => $value, 'source' => $provenance['source'], 'confidence' => $provenance['confidence']],
        ];
    }

    /**
     * @param array<string, string|null> $values
     * @param array{label: string, key: list<string>, columns: list<string>} $definition
     */
    private function assertKnownColumns(string $table, array $values, array $definition): void
    {
        foreach ($definition['key'] as $column) {
            // `null` ist als Schlüsselwert erlaubt (Garagen-Vertrag ohne Fahrzeug); die Suche vergleicht mit `<=>`.
            if (!array_key_exists($column, $values)) {
                throw new LogicException(sprintf('Schlüsselspalte „%s“ von %s fehlt.', $column, $table));
            }
        }
        foreach (array_keys($values) as $column) {
            if (!in_array($column, $definition['key'], true) && !in_array($column, $definition['columns'], true)) {
                throw new LogicException(sprintf('Spalte „%s“ gehört nicht zu %s.', $column, $table));
            }
        }
    }

    /**
     * @param array{label: string, key: list<string>, columns: list<string>} $definition
     * @param array<string, string|null> $values
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope
     * @return array<string, mixed>|null
     */
    private function find(string $table, array $definition, array $values, array $scope): ?array
    {
        $keyConditions = array_map(static fn (string $column): string => $column . ' <=> ?', $definition['key']);
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, %s, source, source_url, confidence FROM %s
                 WHERE garage_id = ? AND status = 'aktiv' AND %s
                 ORDER BY id LIMIT 1 FOR UPDATE",
                implode(', ', [...$definition['key'], ...$definition['columns']]),
                $table,
                implode(' AND ', $keyConditions)
            )
        );
        $statement->execute([$scope['garage_id'], ...array_map(static fn (string $column): ?string => $values[$column], $definition['key'])]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Nicht angegebene Spalten bleiben weg, damit Vorgaben der Tabelle greifen (`event_status` = offen).
     *
     * @param array<string, string|null> $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope
     * @return array{id: int, result: string, open_item_ids: list<int>}
     */
    private function insert(string $table, array $values, array $provenance, array $scope, Actor $actor): array
    {
        $given = array_filter($values, static fn (?string $value): bool => $value !== null);
        $columns = array_keys($given);
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %s (garage_id, %s, source, source_url, confidence, actor, user_id, created_at, updated_at)
                 VALUES (?, %s, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            )
        );
        $statement->execute([
            $scope['garage_id'],
            ...array_values($given),
            $provenance['source'],
            $provenance['source_url'],
            $provenance['confidence'],
            $actor->name,
            $actor->userId,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit->record($actor, $table . '.create', sprintf('%s:%d', $table, $id), $scope['garage_id'], null, $given, $scope['vehicle_id']);

        return ['id' => $id, 'result' => RecordMerger::RESULT_CREATED, 'open_item_ids' => []];
    }

    /**
     * @param array{label: string, key: list<string>, columns: list<string>} $definition
     * @param array<string, mixed> $existing
     * @param array<string, string|null> $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope
     * @return array{id: int, result: string, open_item_ids: list<int>}
     */
    private function reconcile(string $table, array $definition, array $existing, array $values, array $provenance, array $scope, Actor $actor): array
    {
        $id = (int) $existing['id'];
        $isConfirmed = $existing['confidence'] === Provenance::CONFIRMED;
        $before = [];
        $after = [];
        $hasSupplemented = false;
        $hasReplaced = false;
        $disputes = [];

        foreach ($definition['columns'] as $column) {
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
        if ($after !== [] || $isHigher) {
            $this->update($table, $id, $after, $before, $provenance, $isHigher, (string) $existing['confidence'], $scope, $actor);
        }

        $openItemIds = [];
        foreach ($disputes as $column => $dispute) {
            $openItemIds[] = $this->recordDispute($table, $definition['label'], $id, $column, $dispute, $provenance, $scope, $actor);
        }

        $result = match (true) {
            $disputes !== [] => RecordMerger::RESULT_DISPUTE,
            $hasReplaced || $isHigher => RecordMerger::RESULT_UPDATED,
            $hasSupplemented => RecordMerger::RESULT_SUPPLEMENTED,
            default => RecordMerger::RESULT_UNCHANGED,
        };

        return ['id' => $id, 'result' => $result, 'open_item_ids' => $openItemIds];
    }

    /**
     * Wird eine Spalte gesetzt, gilt ab da die neue Herkunft für die Zeile; die Konfidenz sinkt dabei nie.
     *
     * @param array<string, string> $after
     * @param array<string, string> $before
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope
     */
    private function update(string $table, int $id, array $after, array $before, array $provenance, bool $isHigher, string $storedConfidence, array $scope, Actor $actor): void
    {
        $assignments = [...array_map(static fn (string $column): string => $column . ' = ?', array_keys($after)), 'source = ?', 'source_url = ?', 'confidence = ?', 'updated_at = UTC_TIMESTAMP()'];
        $parameters = [...array_values($after), $provenance['source'], $provenance['source_url'], $isHigher ? $provenance['confidence'] : $storedConfidence];

        $statement = $this->pdo->prepare(sprintf('UPDATE %s SET %s WHERE id = ? AND garage_id = ?', $table, implode(', ', $assignments)));
        $statement->execute([...$parameters, $id, $scope['garage_id']]);

        if ($isHigher) {
            $before['confidence'] = $storedConfidence;
            $after['confidence'] = $provenance['confidence'];
        }
        $this->audit->record($actor, $table . '.update', sprintf('%s:%d', $table, $id), $scope['garage_id'], $before === [] ? null : $before, $after, $scope['vehicle_id']);
    }

    /**
     * Gleicher Widerspruch zum zweiten Mal: bestehender Klärfall, kein neuer. Bei Garagen-Verträgen ohne Fahrzeug.
     *
     * @param array{stored: string, incoming: string} $dispute
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @param array{garage_id: int, vehicle_id: int|null, label: string} $scope
     */
    private function recordDispute(string $table, string $label, int $id, string $column, array $dispute, array $provenance, array $scope, Actor $actor): int
    {
        foreach ($this->openItems->findOpenByRef($table, $id, $actor) as $openItem) {
            $proposal = $openItem['proposal'];
            if ($proposal !== null && ($proposal['field'] ?? null) === $column && ($proposal['value'] ?? null) === $dispute['incoming']) {
                return $openItem['id'];
            }
        }

        $proposal = ['field' => $column, 'value' => $dispute['incoming'], 'source' => $provenance['source'], 'source_url' => $provenance['source_url'], 'confidence' => $provenance['confidence']];
        $text = sprintf(
            'Widerspruch bei „%s“ (%s) %s: bestätigt ist „%s“, neu gemeldet wird „%s“ (Quelle: %s, Konfidenz %s). Ein Mensch entscheidet, welcher Wert gilt.',
            $column,
            $label,
            $scope['label'],
            $dispute['stored'],
            $dispute['incoming'],
            $provenance['source'],
            $provenance['confidence']
        );
        $openItemId = $this->openItems->insert($scope['garage_id'], $scope['vehicle_id'], OpenItemRepository::KIND_CONFLICT, $text, $table, $id, $actor, $proposal);
        $this->audit->record($actor, $table . '.dispute', sprintf('%s:%d', $table, $id), $scope['garage_id'], [$column => $dispute['stored']], $proposal + ['open_item_id' => $openItemId], $scope['vehicle_id']);

        return $openItemId;
    }
}
