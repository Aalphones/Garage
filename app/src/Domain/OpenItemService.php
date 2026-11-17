<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Klärfälle: Agenten legen „nachfragen“ und „beobachten“ an und lesen alle; Konflikte entstehen beim Schreiben
 * (`RecordMerger`). Entscheiden — also lösen — darf nur ein Mensch in der Oberfläche (ADR-010, ADR-008).
 */
final class OpenItemService
{
    public const DECISION_EXISTING = 'bestehend';
    public const DECISION_NEW = 'neu';
    public const DECISION_DISCARD = 'verwerfen';
    public const DECISION_DONE = 'erledigt';

    private const CONFLICT_DECISIONS = [self::DECISION_EXISTING, self::DECISION_NEW, self::DECISION_DISCARD];
    private const PLAIN_DECISIONS = [self::DECISION_DONE, self::DECISION_DISCARD];
    private const CREATABLE_KINDS = [OpenItemRepository::KIND_QUESTION, OpenItemRepository::KIND_WATCH];
    private const STATUSES = [OpenItemRepository::STATUS_OPEN, OpenItemRepository::STATUS_RESOLVED, OpenItemRepository::STATUS_DISCARDED, OpenItemRepository::STATUS_ALL];
    private const MAX_TEXT = 1000;
    private const MAX_NOTE = 500;
    private const CREATE_EXAMPLE = '{"vehicle_id": 12, "kind": "nachfragen", "text": "Welcher Ölfilter wurde 2025 verbaut?"}';

    private VehicleAccess $access;
    private OpenItemRepository $items;
    private OpenItemView $view;
    private OpenItemEffect $effect;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->items = new OpenItemRepository($pdo);
        $this->view = new OpenItemView($pdo);
        $this->effect = new OpenItemEffect($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Tool-Form: die Klärfälle der eigenen Garagen, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?int $vehicleId, string $status, Actor $actor): array
    {
        $status = Validate::enum('status', $status, self::STATUSES);
        if ($vehicleId !== null) {
            $this->access->readable($vehicleId, $actor);
        }

        return array_map(
            static fn (array $item): array => [
                'id' => $item['id'],
                'vehicle_id' => $item['vehicle_id'],
                'vehicle_label' => $item['vehicle_label'],
                'kind' => $item['kind'],
                'text' => $item['text'],
                'status' => $item['status'],
                'created_by' => $item['created_by'],
                'created_at' => $item['created_at'],
                'ref_table' => $item['ref_table'],
                'ref_id' => $item['ref_id'],
                'proposal' => $item['proposal'],
                'resolution' => $item['resolution'],
                'resolved_by' => $item['resolved_by'],
                'resolved_at' => $item['resolved_at'],
            ],
            $this->items->list($vehicleId, $status, $actor)
        );
    }

    /**
     * API-Form für die Oberfläche: nur offene, mit Gegenüberstellung `current`/`proposed` und den erlaubten Entscheidungen.
     *
     * @return list<array<string, mixed>>
     */
    public function listForHumans(Actor $actor): array
    {
        $result = [];
        foreach ($this->items->list(null, OpenItemRepository::STATUS_OPEN, $actor) as $item) {
            $pair = $this->view->describe($item, $actor);
            $result[] = [
                'id' => $item['id'],
                'garage_id' => $item['garage_id'],
                'vehicle_id' => $item['vehicle_id'],
                'vehicle_label' => $item['vehicle_label'],
                'kind' => $item['kind'],
                'text' => $item['text'],
                'created_by' => $item['created_by'],
                'created_at' => $item['created_at'],
                'can_resolve' => $actor->via === Actor::VIA_WEB && $actor->canWrite($item['garage_id']),
                'decisions' => self::decisionsFor($item['kind']),
                'current' => $pair['current'],
                'proposed' => $pair['proposed'],
            ];
        }

        return $result;
    }

    /**
     * Ein Agent legt eine Nachfrage oder Beobachtung an; derselbe offene Text am selben Fahrzeug bleibt `unveraendert`.
     *
     * @return array{open_item_id: int, result: string}
     */
    public function create(int $vehicleId, mixed $kind, mixed $text, mixed $suggestion, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $kind, $text, $suggestion, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            if ($kind === OpenItemRepository::KIND_CONFLICT) {
                throw Validate::invalid('kind', 'Konflikte entstehen nur aus Widersprüchen beim Schreiben; angelegt werden nur nachfragen und beobachten.', self::CREATE_EXAMPLE);
            }
            $kind = Validate::enum('kind', $kind, self::CREATABLE_KINDS);
            $text = Validate::text('text', $text, 1, self::MAX_TEXT, self::CREATE_EXAMPLE);
            $suggestion = Validate::optionalText('suggestion', $suggestion, 1, self::MAX_TEXT, self::CREATE_EXAMPLE);

            $existing = $this->items->findOpenByText($vehicleId, $kind, $text, $actor);
            if ($existing !== null) {
                return ['open_item_id' => $existing, 'result' => RecordMerger::RESULT_UNCHANGED];
            }

            $id = $this->items->insert($vehicle['garage_id'], $vehicleId, $kind, $text, null, null, $actor, $suggestion === null ? null : ['suggestion' => $suggestion]);
            $this->audit->record($actor, 'open_item.create', sprintf('open_items:%d', $id), $vehicle['garage_id'], null, ['kind' => $kind, 'text' => $text], $vehicleId);

            return ['open_item_id' => $id, 'result' => RecordMerger::RESULT_CREATED];
        });
    }

    /**
     * Entscheidung eines Menschen. Reihenfolge der Fehler: unbekannt → keine Rechte → schon gelöst → Entscheidung passt nicht.
     *
     * @return array{open_item_id: int, status: string, decision: string}
     */
    public function resolve(int $id, mixed $decision, mixed $note, Actor $actor): array
    {
        if ($actor->via !== Actor::VIA_WEB) {
            throw new DomainError('forbidden', 'Klärfälle löst nur ein Mensch in der Oberfläche.');
        }

        return Transaction::run($this->pdo, function () use ($id, $decision, $note, $actor): array {
            $known = $this->items->find($id, $actor);
            if ($known === null) {
                throw new DomainError('not_found', 'Einen Klärfall mit dieser ID gibt es in deinen Garagen nicht.', ['open_item_id' => $id]);
            }
            // Sperrreihenfolge wie überall: erst das Fahrzeug, dann der Klärfall.
            if ($known['vehicle_id'] !== null) {
                $this->access->writable($known['vehicle_id'], $actor);
            } else {
                $this->access->assertCanWriteGarage($known['garage_id'], $actor);
            }
            $item = $this->items->find($id, $actor, true);
            if ($item === null) {
                throw new DomainError('not_found', 'Einen Klärfall mit dieser ID gibt es in deinen Garagen nicht.', ['open_item_id' => $id]);
            }
            if ($item['status'] !== OpenItemRepository::STATUS_OPEN) {
                throw new DomainError('already_resolved', 'Dieser Klärfall ist bereits entschieden.', ['open_item_id' => $id, 'status' => $item['status']]);
            }

            $decision = Validate::enum('decision', $decision, self::decisionsFor($item['kind']));
            $note = Validate::optionalText('note', $note, 1, self::MAX_NOTE, '{"decision": "neu", "note": "Laut Rechnung."}');

            $changed = $this->effect->apply($item, $decision, $actor);
            $status = $decision === self::DECISION_DISCARD ? OpenItemRepository::STATUS_DISCARDED : OpenItemRepository::STATUS_RESOLVED;
            $resolution = $note === null ? $decision : $decision . ': ' . $note;
            $this->items->resolve($id, $status, $resolution, $actor);

            $this->audit->record(
                $actor,
                'open_item.resolve',
                sprintf('open_items:%d', $id),
                $item['garage_id'],
                ['open_item' => ['status' => OpenItemRepository::STATUS_OPEN, 'kind' => $item['kind'], 'text' => $item['text'], 'ref_table' => $item['ref_table'], 'ref_id' => $item['ref_id'], 'proposal' => $item['proposal']], 'data' => $changed['before']],
                ['open_item' => ['status' => $status, 'decision' => $decision, 'note' => $note], 'data' => $changed['after']],
                $item['vehicle_id']
            );

            return ['open_item_id' => $id, 'status' => $status, 'decision' => $decision];
        });
    }

    /** @return list<string> */
    private static function decisionsFor(string $kind): array
    {
        return $kind === OpenItemRepository::KIND_CONFLICT ? self::CONFLICT_DECISIONS : self::PLAIN_DECISIONS;
    }
}
