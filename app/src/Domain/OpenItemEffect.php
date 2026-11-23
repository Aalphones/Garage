<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/**
 * Was die Entscheidung eines Menschen an den Daten ändert (README „Klärfall-Vorschlag“). „Bisheriger Wert gilt“ und
 * „Verwerfen“ ändern nichts außer: strittige Fakt-Zeile → abgelöst, strittiger Kilometerstand → verworfen; nur „Neuer Wert
 * gilt“ übernimmt den Vorschlag. Läuft in der Transaktion von `OpenItemService::resolve`, das Fahrzeug ist gesperrt.
 */
final class OpenItemEffect
{
    private VehicleRepository $vehicles;
    private RecordMerger $merger;
    private EventService $events;
    private OdometerRepository $readings;
    private TaskRepository $tasks;
    private OdometerService $odometer;

    public function __construct(PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->events = new EventService($pdo);
        $this->readings = new OdometerRepository($pdo);
        $this->tasks = new TaskRepository($pdo);
    }

    /**
     * Ohne `ref_table` (nachfragen, beobachten) gibt es nichts zu ändern; der Klärfall wird nur geschlossen.
     *
     * @param array{vehicle_id: int|null, ref_table: string|null, ref_id: int|null, proposal: array<string, mixed>|null} $item
     * @return array{before: array<string, mixed>, after: array<string, mixed>} was sich an den Daten geändert hat, für den Audit-Eintrag
     */
    public function apply(array $item, string $decision, Actor $actor): array
    {
        $refTable = $item['ref_table'];
        $refId = $item['ref_id'];
        if ($refTable === null || $refId === null) {
            return self::unchanged();
        }
        $proposal = $item['proposal'] ?? [];
        $isAccepted = $decision === OpenItemService::DECISION_NEW;

        return match ($refTable) {
            'vehicle_facts' => $this->resolveFact($refId, $isAccepted, $actor),
            'odometer_readings' => $this->resolveReading($item['vehicle_id'], $refId, $proposal, $isAccepted, $actor),
            'task_rules' => $isAccepted ? $this->acceptRule($refId, $proposal, $actor) : self::unchanged(),
            'events' => $isAccepted ? $this->acceptEvent($refId, $proposal, $actor) : self::unchanged(),
            default => $isAccepted ? $this->acceptColumn($refTable, $refId, $proposal, $actor) : self::unchanged(),
        };
    }

    /** @return array{before: array<string, mixed>, after: array<string, mixed>} */
    private static function unchanged(): array
    {
        return ['before' => [], 'after' => []];
    }

    /**
     * Die strittige Zeile wird aktuell (und die bisherige aktuelle abgelöst) oder abgelöst.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function resolveFact(int $factId, bool $isAccepted, Actor $actor): array
    {
        $disputed = $this->vehicles->findFact($factId, $actor);
        if ($disputed === null) {
            throw new DomainError('not_found', 'Der gemeldete Wert zu diesem Klärfall existiert nicht mehr.');
        }
        if ($disputed['status'] !== VehicleRepository::FACT_DISPUTED) {
            if ($isAccepted) {
                throw new DomainError('invalid_value', 'Der gemeldete Wert ist nicht mehr strittig; „neu“ ist nicht mehr möglich. „verwerfen“ schließt den Klärfall.', ['field' => 'decision']);
            }

            return self::unchanged();
        }

        if (!$isAccepted) {
            $this->vehicles->setFactStatus($factId, VehicleRepository::FACT_SUPERSEDED, $actor);

            return ['before' => ['field' => $disputed['field'], 'value' => $disputed['value'], 'status' => VehicleRepository::FACT_DISPUTED], 'after' => ['status' => VehicleRepository::FACT_SUPERSEDED]];
        }

        $before = ['field' => $disputed['field']];
        foreach ($this->vehicles->currentFacts($disputed['vehicle_id'], $actor) as $current) {
            if ($current['field'] === $disputed['field']) {
                $this->vehicles->supersede($current['id'], $actor);
                $before += ['value' => $current['value'], 'source' => $current['source'], 'confidence' => $current['confidence'], 'fact_id' => $current['id']];
            }
        }
        $this->vehicles->setFactStatus($factId, VehicleRepository::FACT_CURRENT, $actor);

        return [
            'before' => $before,
            'after' => ['field' => $disputed['field'], 'value' => $disputed['value'], 'source' => $disputed['source'], 'confidence' => $disputed['confidence'], 'fact_id' => $factId],
        ];
    }

    /**
     * Der strittige Stand wird gültig und die widersprechenden werden verworfen („neu“), oder er wird verworfen.
     * Gültige Stände, die erst nach dem Klärfall dazukamen und dem neuen widersprechen, werden ihrerseits strittig.
     *
     * @param array<string, mixed> $proposal
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function resolveReading(?int $vehicleId, int $readingId, array $proposal, bool $isAccepted, Actor $actor): array
    {
        $disputed = $this->readings->findByIds([$readingId], $actor)[0] ?? null;
        if ($disputed === null) {
            throw new DomainError('not_found', 'Der Kilometerstand zu diesem Klärfall existiert nicht mehr.');
        }
        $isStillDisputed = $disputed['reading_status'] === OdometerRepository::STATUS_DISPUTED;
        if ($isAccepted && !$isStillDisputed) {
            throw new DomainError('invalid_value', 'Der Kilometerstand ist nicht mehr strittig; „neu“ ist nicht mehr möglich. „verwerfen“ schließt den Klärfall.', ['field' => 'decision']);
        }
        if (!$isStillDisputed) {
            return self::unchanged();
        }

        $summary = ['reading_id' => $readingId, 'read_on' => $disputed['read_on'], 'km' => $disputed['km']];
        if (!$isAccepted) {
            $this->readings->setStatus($readingId, OdometerRepository::STATUS_DISCARDED, $actor);

            return ['before' => $summary + ['reading_status' => OdometerRepository::STATUS_DISPUTED], 'after' => ['reading_status' => OdometerRepository::STATUS_DISCARDED]];
        }

        $conflictIds = array_map(intval(...), is_array($proposal['conflicts_with'] ?? null) ? $proposal['conflicts_with'] : []);
        $conflicts = $this->readings->findByIds($conflictIds, $actor);
        $this->readings->setStatus($readingId, OdometerRepository::STATUS_VALID, $actor);
        foreach ($conflicts as $conflict) {
            $this->readings->setStatus($conflict['id'], OdometerRepository::STATUS_DISCARDED, $actor);
        }
        $newlyDisputed = $this->disputeContradicting($vehicleId, $disputed, $actor);

        return [
            'before' => ['reading' => $summary + ['reading_status' => OdometerRepository::STATUS_DISPUTED], 'conflicts' => array_map(self::readingSummary(...), $conflicts)],
            'after' => ['reading' => $summary + ['reading_status' => OdometerRepository::STATUS_VALID], 'discarded' => array_column($conflicts, 'id'), 'disputed' => $newlyDisputed],
        ];
    }

    /**
     * Kilometer laufen nur vorwärts (ADR-011): jeder noch gültige Stand, der dem gerade gültig gewordenen widerspricht
     * (später und kleiner, früher und größer), wird strittig und bekommt einen Klärfall gegen ihn.
     *
     * @param array{id: int, read_on: string, km: int} $accepted
     * @return list<array{reading_id: int, open_item_id: int}>
     */
    private function disputeContradicting(?int $vehicleId, array $accepted, Actor $actor): array
    {
        $vehicle = $vehicleId === null ? null : $this->vehicles->find($vehicleId, $actor);
        if ($vehicle === null) {
            throw new LogicException('Kilometerstand-Klärfall ohne Fahrzeug.');
        }

        $disputed = [];
        foreach ($this->readings->findConflicts($vehicle['id'], $accepted['read_on'], (string) $accepted['km'], $actor) as $contradicting) {
            $openItemId = $this->odometer->disputeExisting($vehicle, $contradicting, $accepted, $actor);
            $disputed[] = ['reading_id' => $contradicting['id'], 'open_item_id' => $openItemId];
        }

        return $disputed;
    }

    /**
     * @param array{id: int, read_on: string, km: int, reading_status: string} $reading
     * @return array<string, mixed>
     */
    private static function readingSummary(array $reading): array
    {
        return ['reading_id' => $reading['id'], 'read_on' => $reading['read_on'], 'km' => $reading['km'], 'reading_status' => $reading['reading_status']];
    }

    /**
     * Die neue Regel wird aktuell, die bisherige abgelöst; Herkunft aus dem Vorschlag.
     *
     * @param array<string, mixed> $proposal
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function acceptRule(int $ruleId, array $proposal, Actor $actor): array
    {
        $found = $this->tasks->findRuleWithTask($ruleId, $actor);
        if ($found === null) {
            throw new DomainError('not_found', 'Die Intervallregel zu diesem Klärfall existiert nicht mehr.');
        }
        $task = $found['task'];
        $values = self::ruleValues($proposal);
        $provenance = Provenance::validate($proposal, 'proposal');

        $before = [];
        $current = $this->tasks->currentRule($task['id'], $actor);
        if ($current !== null) {
            $this->tasks->supersedeRule($current['id'], $task['garage_id']);
            $before = ['rule_id' => $current['id'], 'interval_km' => $current['interval_km'], 'interval_months' => $current['interval_months'], 'first_km' => $current['first_km'], 'first_months' => $current['first_months'], 'mode' => $current['mode'], 'confidence' => $current['confidence']];
        }
        $newRuleId = $this->tasks->insertRule($task['garage_id'], $task['id'], $values, $provenance, $actor);

        return ['before' => $before, 'after' => ['task_key' => $task['task_key'], 'rule_id' => $newRuleId] + $values + ['confidence' => $provenance['confidence']]];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string}
     */
    private static function ruleValues(array $proposal): array
    {
        $mode = $proposal['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, TaskService::MODES, true)) {
            throw new LogicException('Der Vorschlag der Intervallregel hat keinen gültigen Modus.');
        }

        $numbers = [];
        foreach (['interval_km', 'interval_months', 'first_km', 'first_months'] as $field) {
            $numbers[$field] = ($proposal[$field] ?? null) === null ? null : (string) (int) $proposal[$field];
        }

        return $numbers + ['mode' => $mode, 'valid_from' => Validate::date('valid_from', $proposal['valid_from'] ?? null, '{"valid_from": "2026-03-02"}')];
    }

    /**
     * Ein Ereignis: entweder neue Positionen (`items`) oder eine Spalte (`field`).
     *
     * @param array<string, mixed> $proposal
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function acceptEvent(int $eventId, array $proposal, Actor $actor): array
    {
        if (isset($proposal['items'])) {
            $counts = $this->events->replaceItems($eventId, $proposal, $actor);

            return ['before' => ['items' => $counts['before']], 'after' => ['items' => $counts['after']]];
        }

        return $this->acceptColumn('events', $eventId, $proposal, $actor);
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function acceptColumn(string $table, int $rowId, array $proposal, Actor $actor): array
    {
        if (!isset(RecordMerger::TABLES[$table])) {
            throw new LogicException(sprintf('Klärfall mit unbekannter Tabelle „%s“.', $table));
        }

        return $this->merger->applyProposal($table, $rowId, $proposal, $actor);
    }
}
