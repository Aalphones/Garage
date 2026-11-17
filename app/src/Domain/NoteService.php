<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Notizen je Thema als Freitext ohne Konfliktregel: jede Änderung ist eine neue Revision, die alten bleiben stehen.
 */
final class NoteService
{
    private const MAX_BODY = 20000;
    private const EXAMPLE = '{"vehicle_id": 12, "topic": "Winterlager", "body": "Reifen im Keller, Regal 2."}';
    /** Steuerzeichen außer Tabulator und Zeilenumbruch. */
    private const CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    private VehicleAccess $access;
    private NoteRepository $notes;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->notes = new NoteRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Neues Thema → Revision 1; anderer Text → nächste Revision; gleicher Text → nichts.
     *
     * @param array<string, mixed> $input topic, body
     * @return array{note_id: int, revision: int, result: string}
     */
    public function upsert(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $topic = Validate::text('topic', $input['topic'] ?? null, 1, 120, self::EXAMPLE);
            $body = self::validBody($input['body'] ?? null);

            $current = $this->notes->findWithLatest($vehicleId, $topic, $actor);
            if ($current === null) {
                $noteId = $this->notes->insertNote($vehicle['garage_id'], $vehicleId, $topic);
                $this->notes->insertRevision($vehicle['garage_id'], $noteId, 1, $body, $actor);
                $this->audit->record($actor, 'note.create', sprintf('notes:%d', $noteId), $vehicle['garage_id'], null, ['topic' => $topic, 'revision' => 1, 'length' => mb_strlen($body)], $vehicleId);

                return ['note_id' => $noteId, 'revision' => 1, 'result' => RecordMerger::RESULT_CREATED];
            }
            if ($current['body'] === $body) {
                return ['note_id' => $current['id'], 'revision' => $current['revision'], 'result' => RecordMerger::RESULT_UNCHANGED];
            }

            $revision = $current['revision'] + 1;
            $this->notes->insertRevision($vehicle['garage_id'], $current['id'], $revision, $body, $actor);
            $this->notes->touch($current['id'], $vehicle['garage_id']);
            $this->audit->record(
                $actor,
                'note.revise',
                sprintf('notes:%d', $current['id']),
                $vehicle['garage_id'],
                ['revision' => $current['revision'], 'length' => mb_strlen($current['body'])],
                ['revision' => $revision, 'length' => mb_strlen($body)],
                $vehicleId
            );

            return ['note_id' => $current['id'], 'revision' => $revision, 'result' => RecordMerger::RESULT_UPDATED];
        });
    }

    /**
     * Je Thema die neueste Revision — Grundlage der Akte.
     *
     * @return list<array{note_id: int, topic: string, revision: int, body: string, actor: string, updated_at: string}>
     */
    public function listCurrent(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        return $this->notes->listCurrent($vehicleId, $actor);
    }

    /** Mehrzeiliger Markdown-Text; Zeilenenden werden vereinheitlicht, damit derselbe Text aus anderem System `unveraendert` bleibt. */
    private static function validBody(mixed $raw): string
    {
        $body = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
        $isText = mb_check_encoding($body, 'UTF-8') && preg_match(self::CONTROL_CHARACTERS, $body) !== 1;
        $length = $isText ? mb_strlen($body) : 0;
        if (!$isText || $length < 1 || $length > self::MAX_BODY) {
            throw Validate::invalid('body', sprintf('Erwartet wird Text (Markdown, Zeilenumbrüche erlaubt) mit 1 bis %d Zeichen.', self::MAX_BODY), self::EXAMPLE);
        }

        return $body;
    }
}
