<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Sonstige Ausgaben eines Fahrzeugs: Parken, Wäsche, Maut und Ähnliches, über den Datensatz-Abgleich (ADR-022, ADR-025). */
final class ExpenseService
{
    public const CATEGORIES = ['parken', 'waesche', 'pflege', 'maut', 'reifeneinlagerung', 'bussgeld', 'sonstiges'];
    public const MIN_SPREAD_MONTHS = 1;
    public const MAX_SPREAD_MONTHS = 36;

    private const TABLE = 'expenses';
    private const EXAMPLE = '{"spent_on": "2026-11-12", "category": "parken", "amount_eur": "18.50", "spread_months": 1, "note": "Parkhaus Hauptbahnhof"}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
    }

    /**
     * Datum, Kategorie und Betrag erkennen dieselbe Ausgabe wieder. `spread_months` verteilt den Betrag auf so viele
     * Monate; ohne Angabe gilt 1 und ein schon gespeicherter Wert bleibt unberührt.
     *
     * @param array<string, mixed> $input spent_on, category, amount_eur, spread_months?, note?, source, source_url?, confidence
     * @return array{expense_id: int, result: string, open_item_ids: list<int>}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::values($input);
        $provenance = Provenance::validate($input, 'expense');

        return Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);

            return ['expense_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Ausgabe eines Menschen im Browser (ADR-025): Herkunft `nutzer`/`bestaetigt`, Datum nicht in der Zukunft.
     *
     * @param array<string, mixed> $input spent_on, category, amount_eur, spread_months?, note?
     * @return array{expense_id: int, result: string, open_item_ids: list<int>}
     */
    public function recordByHuman(int $vehicleId, array $input, Actor $actor): array
    {
        $human = [
            'spent_on' => Validate::dateNotInFuture('spent_on', $input['spent_on'] ?? null, self::EXAMPLE, 'Das Datum der Ausgabe liegt in der Zukunft.'),
            'category' => $input['category'] ?? null,
            'amount_eur' => $input['amount_eur'] ?? null,
            'spread_months' => $input['spread_months'] ?? null,
            'note' => $input['note'] ?? null,
        ];

        return $this->record($vehicleId, $human + Provenance::BY_HUMAN, $actor);
    }

    /**
     * Neueste zuerst.
     *
     * @return array{expenses: list<array<string, mixed>>}
     */
    public function list(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        $expenses = [];
        foreach ($this->records->listForVehicle(self::TABLE, $vehicleId, $actor) as $row) {
            $expenses[] = [
                'id' => (int) $row['id'],
                'spent_on' => (string) $row['spent_on'],
                'category' => (string) $row['category'],
                'amount_eur' => Money::centsToEuro((int) $row['amount_cents']),
                'spread_months' => (int) $row['spread_months'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return ['expenses' => $expenses];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function values(array $input): array
    {
        $cents = Validate::euroToCents('amount_eur', $input['amount_eur'] ?? null);
        if ($cents === null || (int) $cents < 1) {
            throw Validate::invalid('amount_eur', 'Erwartet wird ein Betrag über 0 Euro mit höchstens zwei Nachkommastellen.', '{"amount_eur": "18.50"}');
        }

        $values = [
            'spent_on' => Validate::date('spent_on', $input['spent_on'] ?? null, self::EXAMPLE),
            'category' => Validate::enum('category', $input['category'] ?? null, self::CATEGORIES),
            'amount_cents' => $cents,
            'note' => Validate::optionalText('note', $input['note'] ?? null, 1, 200, '{"note": "Parkhaus Hauptbahnhof"}'),
        ];
        $spread = Validate::optionalInt('spread_months', $input['spread_months'] ?? null, '{"spread_months": 12}', self::MIN_SPREAD_MONTHS, self::MAX_SPREAD_MONTHS);
        if ($spread !== null) {
            $values['spread_months'] = $spread;
        }

        return $values;
    }
}
