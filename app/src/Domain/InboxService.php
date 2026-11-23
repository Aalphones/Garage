<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Garage\Db\Transaction;
use PDO;

/**
 * Eingang (E11, ADR-016): Menschen legen Dokumente zur Abarbeitung ab, ein Agent reserviert einen Eintrag mit Frist,
 * erfasst und schließt ihn ab. Die Frist läuft ohne Cron: `in_arbeit` mit abgelaufener Frist gilt beim Lesen als `neu`.
 */
final class InboxService
{
    public const OUTCOMES = ['erfasst', 'zurueck', 'klaerfall'];
    public const LIST_STATUSES = ['neu', 'in_arbeit', 'erfasst', 'klaerfall', 'verworfen', 'alle'];
    public const DEFAULT_MINUTES = 180;
    public const MIN_MINUTES = 10;
    public const MAX_MINUTES = 720;

    private const MAX_NOTE = 200;
    private const MAX_RESULT = 500;
    private const MAX_DOCUMENTS = 50;
    private const OPEN_ITEM_TEXT = 1000;
    private const CREATE_EXAMPLE = '{"document_ids": [4, 5, 6], "vehicle_id": 12, "note": "Inspektion Mai"}';
    private const RELEASE_EXAMPLE = '{"id": 3, "outcome": "erfasst", "result": "Inspektion 05/2025 erfasst"}';

    private InboxRepository $inbox;
    private DocumentRepository $documents;
    private DocumentService $documentService;
    private VehicleAccess $access;
    private OpenItemRepository $openItems;
    private AuditRepository $audit;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly PDO $pdo, array $config)
    {
        $this->inbox = new InboxRepository($pdo);
        $this->documents = new DocumentRepository($pdo);
        $this->documentService = new DocumentService($pdo, $config);
        $this->access = new VehicleAccess($pdo);
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Ein Mensch legt Dokumente (Seiten in dieser Reihenfolge) als Eingangs-Eintrag ab.
     *
     * @param mixed $documentIds
     * @return array{id: int}
     */
    public function create(mixed $documentIds, mixed $vehicleId, mixed $note, Actor $actor): array
    {
        $this->assertHuman($actor);
        $ids = self::ids($documentIds);
        $noteText = Validate::optionalText('note', $note, 1, self::MAX_NOTE, self::CREATE_EXAMPLE) ?? '';

        return Transaction::run($this->pdo, function () use ($ids, $vehicleId, $noteText, $actor): array {
            $garageId = null;
            foreach ($ids as $documentId) {
                $document = $this->documents->find($documentId, $actor, true);
                if ($document === null) {
                    throw new DomainError('not_found', 'Ein Dokument mit dieser ID gibt es in deinen Garagen nicht.', ['document_id' => $documentId]);
                }
                if ($garageId !== null && $garageId !== $document['garage_id']) {
                    throw Validate::invalid('document_ids', 'Alle Dokumente eines Eintrags müssen in derselben Garage liegen.', self::CREATE_EXAMPLE);
                }
                $garageId = $document['garage_id'];
                if (in_array($document['status'], [DocumentRepository::STATUS_WAITING, DocumentRepository::STATUS_DISCARDED], true)) {
                    throw Validate::invalid('document_ids', sprintf('Dokument %d hat keine Datei oder ist verworfen (Status „%s“).', $documentId, $document['status']), self::CREATE_EXAMPLE);
                }
            }
            $garageId = (int) $garageId;
            $this->access->assertCanWriteGarage($garageId, $actor);

            $vehicle = null;
            if ($vehicleId !== null && $vehicleId !== '') {
                $vehicle = $this->access->readable((int) $vehicleId, $actor);
                if ($vehicle['garage_id'] !== $garageId) {
                    throw new DomainError('not_found', 'Ein Fahrzeug mit dieser ID gibt es in der Garage der Dokumente nicht.', ['vehicle_id' => (int) $vehicleId]);
                }
            }

            $id = $this->inbox->insert($garageId, $vehicle === null ? null : $vehicle['id'], $noteText, $actor);
            $this->inbox->addDocuments($id, $ids);
            $this->audit->record($actor, 'inbox.create', sprintf('inbox_items:%d', $id), $garageId, null, ['documents' => $ids, 'note' => $noteText], $vehicle === null ? null : $vehicle['id']);

            return ['id' => $id];
        });
    }

    /**
     * Tool-Form: Standard `neu`, älteste zuerst. Der Status ist der wirksame (abgelaufene Reservierungen erscheinen als `neu`).
     *
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, ?string $status, ?int $garageId): array
    {
        $status = $status === null ? InboxRepository::STATUS_NEW : Validate::enum('status', $status, self::LIST_STATUSES);
        if ($garageId !== null && !$actor->canRead($garageId)) {
            throw new DomainError('not_found', 'Eine Garage mit dieser ID gibt es bei dir nicht. list_vehicles nennt deine Garagen.', ['garage_id' => $garageId]);
        }

        return array_map(fn (array $row): array => $this->present($row), $this->inbox->list($actor, $status, $garageId, false));
    }

    /**
     * Web-Form: alle Garagen, neueste zuerst, verworfene nur 30 Tage; mit den Dokumenten und ihren Vorschau-Links.
     *
     * @return list<array<string, mixed>>
     */
    public function listForHumans(Actor $actor): array
    {
        $result = [];
        foreach ($this->inbox->list($actor, null, null, true) as $row) {
            $documents = [];
            foreach ($this->inbox->documentIds($row['id']) as $documentId) {
                $document = $this->documents->find($documentId, $actor);
                if ($document === null) {
                    continue;
                }
                $preview = $this->documentService->previewFile($document);
                $documents[] = [
                    'id' => $document['id'],
                    'doc_type' => $document['doc_type'],
                    'title' => $document['title'],
                    'page_count' => $document['page_count'],
                    'preview_url' => $preview === null ? null : $this->documentService->linkFor($document, $preview),
                ];
            }
            $result[] = $this->present($row) + [
                'open_item_id' => $row['open_item_id'],
                'can_edit' => $actor->canWrite($row['garage_id']),
                'documents' => $documents,
            ];
        }

        return $result;
    }

    /**
     * Reserviert einen Eintrag (`$id`) oder den ältesten freien. Frei ist: wirksamer Status `neu`, oder bereits vom selben Akteur
     * reserviert (die Frist wird verlängert). Gibt es keinen freien Eintrag, kommt `null`.
     *
     * @return array{item: array<string, mixed>, documents: list<array<string, mixed>>}|null
     */
    public function claim(?int $id, int $minutes, Actor $actor): ?array
    {
        return Transaction::run($this->pdo, function () use ($id, $minutes, $actor): ?array {
            $id ??= $this->inbox->oldestClaimable(array_values(array_filter($actor->garageIds(), static fn (int $garageId): bool => $actor->canWrite($garageId))));
            if ($id === null) {
                return null;
            }
            $item = $this->inbox->find($id, $actor, true);
            if ($item === null) {
                throw $this->notFound($id);
            }
            $this->access->assertCanWriteGarage($item['garage_id'], $actor);

            $isMine = $item['status'] === InboxRepository::STATUS_WORKING && $item['claimed_by'] === $actor->name;
            if (!$isMine && self::effectiveStatus($item) !== InboxRepository::STATUS_NEW) {
                throw $this->notClaimable($item);
            }

            $this->inbox->claim($id, $actor->name, (int) $actor->userId, $minutes);
            $this->audit->record($actor, 'inbox.claim', sprintf('inbox_items:%d', $id), $item['garage_id'], null, ['minutes' => $minutes, 'extended' => $isMine], $item['vehicle_id']);

            $claimed = $this->inbox->find($id, $actor) ?? $item;
            $documents = [];
            foreach ($this->inbox->documentIds($id) as $documentId) {
                $document = $this->documents->find($documentId, $actor);
                if ($document !== null) {
                    $documents[] = $document;
                }
            }

            return ['item' => $this->present($claimed), 'documents' => $documents];
        });
    }

    /**
     * Schließt die Reservierung ab. Nur wer reserviert hat und dessen Frist noch läuft (Rohwert-Vergleich in SQL).
     *
     * @return array{id: int, status: string}
     */
    public function release(int $id, mixed $outcome, mixed $result, Actor $actor): array
    {
        $outcome = Validate::enum('outcome', $outcome, self::OUTCOMES);
        $text = $outcome === 'zurueck'
            ? Validate::optionalText('result', $result, 1, self::MAX_RESULT, self::RELEASE_EXAMPLE)
            : Validate::text('result', $result, 1, self::MAX_RESULT, self::RELEASE_EXAMPLE);

        return Transaction::run($this->pdo, function () use ($id, $outcome, $text, $actor): array {
            // Sperrreihenfolge wie überall: erst das Fahrzeug, dann der Eintrag.
            $known = $this->inbox->find($id, $actor);
            if ($known === null) {
                throw $this->notFound($id);
            }
            if ($known['vehicle_id'] !== null) {
                $this->access->writable($known['vehicle_id'], $actor);
            } else {
                $this->access->assertCanWriteGarage($known['garage_id'], $actor);
            }
            $item = $this->inbox->find($id, $actor, true) ?? throw $this->notFound($id);
            $this->assertHeldBy($item, $actor);

            $openItemId = null;
            $status = match ($outcome) {
                'erfasst' => InboxRepository::STATUS_DONE,
                'klaerfall' => InboxRepository::STATUS_QUESTION,
                default => InboxRepository::STATUS_NEW,
            };
            if ($outcome === 'klaerfall') {
                $question = mb_substr(sprintf('Eingang #%d: %s', $id, (string) $text), 0, self::OPEN_ITEM_TEXT);
                $openItemId = $this->openItems->insert($item['garage_id'], $item['vehicle_id'], OpenItemRepository::KIND_QUESTION, $question, null, null, $actor);
            }
            $this->inbox->finish($id, $status, $text, $openItemId);
            $this->audit->record($actor, 'inbox.release', sprintf('inbox_items:%d', $id), $item['garage_id'], null, ['outcome' => $outcome, 'result' => $text, 'open_item_id' => $openItemId], $item['vehicle_id']);

            return ['id' => $id, 'status' => $status];
        });
    }

    /** Ein Mensch verwirft einen Eintrag; nur aus `neu` (auch eine abgelaufene Reservierung zählt als `neu`). */
    public function discard(int $id, Actor $actor): void
    {
        $this->assertHuman($actor);
        Transaction::run($this->pdo, function () use ($id, $actor): void {
            $item = $this->writableItem($id, $actor);
            if (self::effectiveStatus($item) !== InboxRepository::STATUS_NEW) {
                throw new DomainError('invalid_value', sprintf('Nur ein neuer Eintrag lässt sich verwerfen; dieser ist „%s“.', self::effectiveStatus($item)), ['id' => $id]);
            }
            $this->inbox->setStatus($id, InboxRepository::STATUS_DISCARDED);
            $this->audit->record($actor, 'inbox.discard', sprintf('inbox_items:%d', $id), $item['garage_id'], null, null, $item['vehicle_id']);
        });
    }

    /** Ein Mensch ändert die Notiz eines Eintrags, solange ihn kein Agent bearbeitet; leer löscht die Notiz. */
    public function updateNote(int $id, mixed $note, Actor $actor): void
    {
        $this->assertHuman($actor);
        $noteText = Validate::optionalText('note', $note, 1, self::MAX_NOTE, self::CREATE_EXAMPLE) ?? '';
        Transaction::run($this->pdo, function () use ($id, $noteText, $actor): void {
            $item = $this->writableItem($id, $actor);
            if (self::effectiveStatus($item) !== InboxRepository::STATUS_NEW) {
                throw new DomainError('invalid_value', sprintf('Die Notiz lässt sich nur an einem neuen Eintrag ändern; dieser ist „%s“.', self::effectiveStatus($item)), ['id' => $id]);
            }
            $this->inbox->setNote($id, $noteText);
            $this->audit->record($actor, 'inbox.note', sprintf('inbox_items:%d', $id), $item['garage_id'], ['note' => $item['note']], ['note' => $noteText], $item['vehicle_id']);
        });
    }

    /** Ein Mensch holt einen verworfenen Eintrag zurück nach `neu`. */
    public function restore(int $id, Actor $actor): void
    {
        $this->assertHuman($actor);
        Transaction::run($this->pdo, function () use ($id, $actor): void {
            $item = $this->writableItem($id, $actor);
            if ($item['status'] !== InboxRepository::STATUS_DISCARDED) {
                throw new DomainError('invalid_value', 'Nur ein verworfener Eintrag lässt sich zurückholen.', ['id' => $id]);
            }
            $this->inbox->setStatus($id, InboxRepository::STATUS_NEW);
            $this->audit->record($actor, 'inbox.restore', sprintf('inbox_items:%d', $id), $item['garage_id'], null, null, $item['vehicle_id']);
        });
    }

    /**
     * Wirksamer Status: die einzige Stelle, die „Frist abgelaufen“ in „neu“ übersetzt. Verglichen wird der Rohwert
     * `claimed_until` (als `is_claim_live` aus SQL), nie ein abgeleiteter Status.
     *
     * @param array<string, mixed> $item Zeile aus dem Repository
     */
    public static function effectiveStatus(array $item): string
    {
        if ($item['status'] === InboxRepository::STATUS_WORKING && !$item['is_claim_live']) {
            return InboxRepository::STATUS_NEW;
        }

        return (string) $item['status'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $status = self::effectiveStatus($row);
        $isWorking = $status === InboxRepository::STATUS_WORKING;

        return [
            'id' => $row['id'],
            'garage_id' => $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'],
            'note' => $row['note'],
            'status' => $status,
            'claimed_by' => $isWorking ? $row['claimed_by'] : null,
            'claimed_until' => $isWorking ? self::iso($row['claimed_until']) : null,
            'page_count' => $row['page_count'],
            'created_at' => self::iso($row['created_at']),
            'result' => $row['result'],
        ];
    }

    /** @param array<string, mixed> $item */
    private function assertHeldBy(array $item, Actor $actor): void
    {
        $example = self::RELEASE_EXAMPLE;
        if ($item['status'] !== InboxRepository::STATUS_WORKING) {
            throw Validate::invalid('id', sprintf('Dieser Eintrag ist nicht reserviert (Status „%s“). Erst claim_inbox_item aufrufen.', self::effectiveStatus($item)), $example);
        }
        if ($item['claimed_by'] !== $actor->name || !$item['is_claim_live']) {
            throw Validate::invalid('id', 'Reservierung abgelaufen oder von jemand anderem übernommen. Erneut claim_inbox_item aufrufen.', $example);
        }
    }

    /** @param array<string, mixed> $item */
    private function notClaimable(array $item): DomainError
    {
        $status = self::effectiveStatus($item);
        if ($status === InboxRepository::STATUS_WORKING) {
            return new DomainError('invalid_value', sprintf('Dieser Eintrag ist reserviert bis %s (von %s). Ein anderer Eintrag oder später erneut.', self::iso($item['claimed_until']), $item['claimed_by']), ['id' => $item['id']]);
        }

        return new DomainError('invalid_value', sprintf('Dieser Eintrag ist schon abgeschlossen (Status „%s“).', $status), ['id' => $item['id']]);
    }

    /** @return array<string, mixed> */
    private function writableItem(int $id, Actor $actor): array
    {
        $item = $this->inbox->find($id, $actor, true);
        if ($item === null) {
            throw $this->notFound($id);
        }
        $this->access->assertCanWriteGarage($item['garage_id'], $actor);

        return $item;
    }

    private function assertHuman(Actor $actor): void
    {
        if ($actor->via !== Actor::VIA_WEB) {
            throw new DomainError('forbidden', 'Den Eingang füllt und räumt nur ein Mensch in der Oberfläche.');
        }
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw) || count($raw) > self::MAX_DOCUMENTS) {
            throw Validate::invalid('document_ids', sprintf('Eine nicht leere Liste von 1 bis %d Dokument-IDs.', self::MAX_DOCUMENTS), self::CREATE_EXAMPLE);
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw Validate::invalid('document_ids', 'Jeder Eintrag ist eine Dokument-ID (ganze Zahl).', self::CREATE_EXAMPLE);
            }
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    private static function iso(?string $utc): ?string
    {
        if ($utc === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        return $date === false ? $utc : $date->format('Y-m-d\TH:i:s\Z');
    }

    private function notFound(int $id): DomainError
    {
        return new DomainError('not_found', 'Einen Eingangs-Eintrag mit dieser ID gibt es in deinen Garagen nicht. list_inbox zeigt die vorhandenen.', ['id' => $id]);
    }
}
