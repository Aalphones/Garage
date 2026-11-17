<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Wartungsaufgaben und ihre Intervallregeln. Eine Regel ist ein Ganzes (Kilometer, Monate, Erstintervall, Modus): eine
 * bestätigte Regel überschreibt nichts, ein Widerspruch wird ein Klärfall mit der neuen Regel als Vorschlag (ADR-009).
 */
final class TaskService
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,40}$/';
    public const MODES = ['fest', 'flexibel'];

    private const MAX_KM = 1000000;
    private const MAX_MONTHS = 240;
    private const RULE_FIELDS = ['interval_km', 'interval_months', 'first_km', 'first_months', 'mode'];
    private const EXAMPLE = '{"vehicle_id": 12, "task_key": "oelwechsel", "title": "Ölwechsel", "interval_km": 15000, "interval_months": 24, "mode": "flexibel", "source": "werkstatt", "confidence": "bestaetigt"}';

    private TaskRepository $tasks;
    private VehicleAccess $access;
    private OpenItemRepository $openItems;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->tasks = new TaskRepository($pdo);
        $this->access = new VehicleAccess($pdo);
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Legt Aufgabe und Regel an oder gleicht die Regel mit der gespeicherten ab.
     *
     * @param array<string, mixed> $input siehe `upsert_task_rule`
     * @return array{task_id: int, rule_id: int, result: string, open_item_id?: int}
     */
    public function upsertRule(array $input, Actor $actor): array
    {
        $vehicleId = (int) Validate::int('vehicle_id', $input['vehicle_id'] ?? null, '{"vehicle_id": 12}', 1);
        $taskKey = self::validKey($input['task_key'] ?? null);
        $title = Validate::optionalText('title', $input['title'] ?? null, 1, 120, self::EXAMPLE);
        $values = self::validRule($input);
        $validFrom = ($input['valid_from'] ?? null) === null ? null : Validate::date('valid_from', $input['valid_from'], '{"valid_from": "2026-03-02"}');
        $provenance = Provenance::validate($input, 'upsert_task_rule');

        return Transaction::run($this->pdo, function () use ($vehicleId, $taskKey, $title, $values, $validFrom, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $values['valid_from'] = $validFrom ?? $this->tasks->utcToday();
            $task = $this->tasks->findTask($vehicleId, $taskKey, $actor);
            if ($task === null) {
                return $this->createTask($vehicle, $taskKey, $title, $values, $provenance, $actor);
            }

            return $this->reconcile($vehicle, $task, $title, $values, $provenance, $actor);
        });
    }

    /**
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string} $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @return array{task_id: int, rule_id: int, result: string}
     */
    private function createTask(array $vehicle, string $taskKey, ?string $title, array $values, array $provenance, Actor $actor): array
    {
        if ($title === null) {
            throw Validate::invalid('title', sprintf('Für die neue Aufgabe „%s“ fehlt der Titel (1 bis 120 Zeichen).', $taskKey), self::EXAMPLE);
        }

        $taskId = $this->tasks->insertTask($vehicle['garage_id'], $vehicle['id'], $taskKey, $title, $actor);
        $ruleId = $this->tasks->insertRule($vehicle['garage_id'], $taskId, $values, $provenance, $actor);
        $this->audit->record(
            $actor,
            'task_rule.create',
            sprintf('task_rules:%d', $ruleId),
            $vehicle['garage_id'],
            null,
            ['task_key' => $taskKey, 'title' => $title, 'task_id' => $taskId] + $values + ['source' => $provenance['source'], 'confidence' => $provenance['confidence']],
            $vehicle['id']
        );

        return ['task_id' => $taskId, 'rule_id' => $ruleId, 'result' => RecordMerger::RESULT_CREATED];
    }

    /**
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{id: int, garage_id: int, vehicle_id: int, task_key: string, title: string} $task
     * @param array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string} $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     * @return array{task_id: int, rule_id: int, result: string, open_item_id?: int}
     */
    private function reconcile(array $vehicle, array $task, ?string $title, array $values, array $provenance, Actor $actor): array
    {
        $hasNewTitle = $title !== null && $title !== $task['title'];
        if ($hasNewTitle) {
            $this->tasks->updateTitle($task['id'], $vehicle['garage_id'], $title);
            $this->audit->record($actor, 'task.update', sprintf('tasks:%d', $task['id']), $vehicle['garage_id'], ['title' => $task['title']], ['title' => $title], $vehicle['id']);
        }

        $current = $this->tasks->currentRule($task['id'], $actor);
        if ($current === null) {
            $ruleId = $this->tasks->insertRule($vehicle['garage_id'], $task['id'], $values, $provenance, $actor);

            return ['task_id' => $task['id'], 'rule_id' => $ruleId, 'result' => RecordMerger::RESULT_CREATED];
        }

        $isSame = self::ruleValues($current) === self::ruleValues($values);
        if ($isSame && !Provenance::isHigher($provenance['confidence'], $current['confidence'])) {
            return ['task_id' => $task['id'], 'rule_id' => $current['id'], 'result' => $hasNewTitle ? RecordMerger::RESULT_UPDATED : RecordMerger::RESULT_UNCHANGED];
        }
        if ($isSame || $current['confidence'] !== Provenance::CONFIRMED) {
            $ruleId = $this->replaceRule($vehicle, $task, $current, $values, $provenance, $actor);

            return ['task_id' => $task['id'], 'rule_id' => $ruleId, 'result' => RecordMerger::RESULT_UPDATED];
        }

        return ['task_id' => $task['id'], 'rule_id' => $current['id'], 'result' => RecordMerger::RESULT_DISPUTE, 'open_item_id' => $this->dispute($vehicle, $task, $current, $values, $provenance, $actor)];
    }

    /**
     * Neue Regel als aktuell; die bisherige bleibt als abgelöste Zeile stehen.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{id: int, garage_id: int, vehicle_id: int, task_key: string, title: string} $task
     * @param array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string} $current
     * @param array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string} $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     */
    private function replaceRule(array $vehicle, array $task, array $current, array $values, array $provenance, Actor $actor): int
    {
        $this->tasks->supersedeRule($current['id'], $vehicle['garage_id']);
        $ruleId = $this->tasks->insertRule($vehicle['garage_id'], $task['id'], $values, $provenance, $actor);
        $this->audit->record(
            $actor,
            'task_rule.update',
            sprintf('task_rules:%d', $ruleId),
            $vehicle['garage_id'],
            self::ruleValues($current) + ['confidence' => $current['confidence'], 'rule_id' => $current['id']],
            self::ruleValues($values) + ['confidence' => $provenance['confidence'], 'rule_id' => $ruleId],
            $vehicle['id']
        );

        return $ruleId;
    }

    /**
     * Eine abweichende Regel gegen eine bestätigte: die bestätigte bleibt, ein Klärfall trägt die neue als Vorschlag.
     * Dieselbe Abweichung noch einmal ergibt denselben Klärfall.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{id: int, garage_id: int, vehicle_id: int, task_key: string, title: string} $task
     * @param array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string} $current
     * @param array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string} $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     */
    private function dispute(array $vehicle, array $task, array $current, array $values, array $provenance, Actor $actor): int
    {
        $incoming = self::ruleValues($values);
        foreach ($this->openItems->findOpenByRef('task_rules', $current['id'], $actor) as $openItem) {
            if ($openItem['proposal'] !== null && self::ruleValues($openItem['proposal']) === $incoming) {
                return $openItem['id'];
            }
        }

        $proposal = [
            'interval_km' => self::optionalNumber($values['interval_km']),
            'interval_months' => self::optionalNumber($values['interval_months']),
            'first_km' => self::optionalNumber($values['first_km']),
            'first_months' => self::optionalNumber($values['first_months']),
            'mode' => $values['mode'],
            'valid_from' => $values['valid_from'],
            'source' => $provenance['source'],
            'source_url' => $provenance['source_url'],
            'confidence' => $provenance['confidence'],
        ];
        $text = sprintf(
            'Intervallregel ‚%s‘ am Fahrzeug ‚%s‘: bestätigt ist %s, neu gemeldet %s (Quelle: %s, Konfidenz %s). Ein Mensch entscheidet.',
            $task['task_key'],
            $vehicle['label'],
            self::describe(self::ruleValues($current)),
            self::describe($incoming),
            $provenance['source'],
            $provenance['confidence']
        );
        $openItemId = $this->openItems->insert($vehicle['garage_id'], $vehicle['id'], OpenItemRepository::KIND_CONFLICT, $text, 'task_rules', $current['id'], $actor, $proposal);
        $this->audit->record(
            $actor,
            'task_rule.dispute',
            sprintf('task_rules:%d', $current['id']),
            $vehicle['garage_id'],
            self::ruleValues($current),
            $incoming + ['open_item_id' => $openItemId],
            $vehicle['id']
        );

        return $openItemId;
    }

    /**
     * Die fünf Felder, die eine Regel ausmachen, als Text (`null` = leer); `valid_from` und Herkunft zählen nicht mit.
     *
     * @param array<string, mixed> $source
     * @return array<string, string|null>
     */
    private static function ruleValues(array $source): array
    {
        $values = [];
        foreach (self::RULE_FIELDS as $field) {
            $value = $source[$field] ?? null;
            $values[$field] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /** @param array<string, string|null> $rule */
    private static function describe(array $rule): string
    {
        $parts = [sprintf('alle %s', self::units($rule['interval_km'], $rule['interval_months']))];
        if ($rule['first_km'] !== null || $rule['first_months'] !== null) {
            $parts[] = sprintf('Erstintervall %s', self::units($rule['first_km'], $rule['first_months']));
        }
        $parts[] = (string) $rule['mode'];

        return implode(', ', $parts);
    }

    private static function units(?string $km, ?string $months): string
    {
        $units = [];
        if ($km !== null) {
            $units[] = number_format((int) $km, 0, ',', '.') . ' km';
        }
        if ($months !== null) {
            $units[] = $months . ' Monate';
        }

        return $units === [] ? '–' : implode(' oder ', $units);
    }

    private static function optionalNumber(?string $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string}
     */
    private static function validRule(array $input): array
    {
        $values = [
            'interval_km' => self::optionalInt('interval_km', $input['interval_km'] ?? null, self::MAX_KM),
            'interval_months' => self::optionalInt('interval_months', $input['interval_months'] ?? null, self::MAX_MONTHS),
            'first_km' => self::optionalInt('first_km', $input['first_km'] ?? null, self::MAX_KM),
            'first_months' => self::optionalInt('first_months', $input['first_months'] ?? null, self::MAX_MONTHS),
            'mode' => ($input['mode'] ?? null) === null ? self::MODES[0] : Validate::enum('mode', $input['mode'], self::MODES),
        ];
        if ($values['interval_km'] === null && $values['interval_months'] === null) {
            throw Validate::invalid('interval_km', 'Eine Regel braucht interval_km, interval_months oder beides.', '{"interval_km": 15000, "interval_months": 24}');
        }

        return $values;
    }

    private static function optionalInt(string $field, mixed $raw, int $max): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $exampleValue = str_ends_with($field, 'months') ? 24 : 15000;

        return Validate::int($field, $raw, sprintf('{"%s": %d}', $field, $exampleValue), 1, $max);
    }

    private static function validKey(mixed $raw): string
    {
        if (!is_string($raw) || preg_match(self::KEY_PATTERN, $raw) !== 1) {
            throw Validate::invalid('task_key', 'Der Aufgabenschlüssel besteht aus Kleinbuchstaben, Ziffern und Unterstrichen und beginnt mit einem Buchstaben (2 bis 41 Zeichen).', '{"task_key": "oelwechsel"}');
        }

        return $raw;
    }
}
