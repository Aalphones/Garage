<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Finfo;
use Garage\Auth\FileLink;
use Garage\Auth\Scopes;
use Garage\Db\Transaction;
use Garage\Util\ImageProcessor;
use PDO;
use Throwable;

/**
 * Belege und Fotos (E1–E8, ADR-012): Ablage der Dateien, Metadaten, Upload-Tickets und signierte Links.
 * Kennt kein HTTP; MCP und Web-API rufen dieselben Methoden.
 */
final class DocumentService
{
    public const TYPES = ['foto', 'rechnung', 'hu_bericht', 'police', 'bescheid', 'fahrzeugschein', 'kaufvertrag', 'sonstiges'];
    public const TAGS = ['aussen', 'innen', 'motorraum', 'tacho', 'reifen', 'schaden'];
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    public const LIST_STATUSES = ['wartet', 'unzugeordnet', 'zugeordnet', 'offen', 'verworfen', 'alle'];

    public const CHANNEL_TICKET = 'ticket';
    public const CHANNEL_WIDGET = 'widget';

    private const MAX_TEXT = 200000;
    private const MAX_TITLE = 200;
    private const TEXT_CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    private const EXAMPLE = '{"ticket_id": 7, "vehicle_id": 12, "doc_type": "rechnung", "title": "Rechnung Werkstatt Müller", "text": "Ölwechsel 89,00 EUR"}';
    private const PAGE_FILE = '#^pages/([0-9]{1,2})\.jpg$#';

    private DocumentRepository $documents;
    private VehicleAccess $access;
    private AuditRepository $audit;
    private ImageProcessor $images;
    /** @var array<string, mixed> */
    private array $documentsConfig;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
        $this->documents = new DocumentRepository($pdo);
        $this->access = new VehicleAccess($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->documentsConfig = (array) $config['documents'];
        $this->images = new ImageProcessor($this->documentsConfig);
    }

    /**
     * `store_document`: mit `ticket_id` die hochgeladene Datei beschreiben, ohne Datei ein Dokument im Status `wartet` anlegen.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> document_id, status, duplicate, files, bei `wartet` zusätzlich upload
     */
    public function store(array $input, Actor $actor): array
    {
        if (isset($input['ticket_id'])) {
            return $this->describeUploaded((int) $input['ticket_id'], $input, $actor);
        }

        return $this->createWaiting($input, $actor);
    }

    /**
     * Ticket anlegen. Die Garage kommt aus dem Dokument (`wartet`), dem Fahrzeug, der Angabe oder der einzigen Garage mit Schreibrecht.
     *
     * @return array{ticket_id: int, upload_url: string, expires_at: string, max_bytes: int, accepted: list<string>}
     */
    public function issueTicket(Actor $actor, ?int $garageId, ?int $vehicleId, ?int $documentId = null): array
    {
        return Transaction::join($this->pdo, function () use ($actor, $garageId, $vehicleId, $documentId): array {
            $target = $this->resolveTarget($actor, $garageId, $vehicleId, $documentId);

            return $this->insertTicket($actor, $target['garage_id'], $target['vehicle_id'], $documentId);
        });
    }

    /**
     * Löst ein Ticket ein: legt die Datei ab. Der Slug im Pfad ist die Berechtigung; der Akteur wird aus dem Ticket gebaut.
     *
     * @return array{ticket_id: int, document_id: int, status: string, duplicate: bool}
     */
    public function redeemTicket(string $slug, string $bytes): array
    {
        return Transaction::run($this->pdo, function () use ($slug, $bytes): array {
            $ticket = $this->documents->findTicketByHash(hash('sha256', $slug), true);
            if ($ticket === null) {
                throw new DomainError('ticket_not_found', 'Diese Upload-Adresse kennt GARAGE nicht. request_upload liefert eine neue.');
            }
            if ($ticket['is_redeemed']) {
                throw new DomainError('ticket_used', 'Diese Upload-Adresse wurde schon benutzt. request_upload liefert eine neue.');
            }
            if ($ticket['is_expired']) {
                throw new DomainError('ticket_expired', 'Diese Upload-Adresse ist abgelaufen. request_upload liefert eine neue.');
            }

            $actor = Actor::forUser($ticket['user_id'], Actor::VIA_MCP, 'upload', [Scopes::FILES], $this->pdo);
            if (!$this->mayStore($ticket['garage_id'], $actor)) {
                // Die Mitgliedschaft kann seit der Ausgabe entfallen sein; nach außen sieht das wie ein unbekanntes Ticket aus.
                throw new DomainError('ticket_not_found', 'Diese Upload-Adresse kennt GARAGE nicht. request_upload liefert eine neue.');
            }

            $document = $this->storeBytes($bytes, self::CHANNEL_TICKET, $actor, $ticket['garage_id'], $ticket['vehicle_id'], $ticket['document_id'], $ticket['max_bytes']);
            $this->documents->redeemTicket($ticket['id'], $document['id'], $document['duplicate']);

            return ['ticket_id' => $ticket['id'], 'document_id' => $document['id'], 'status' => $document['status'], 'duplicate' => $document['duplicate']];
        });
    }

    /**
     * Legt die Bytes ab: Größe und Typ prüfen, Dubletten je Garage erkennen, Bilder neu kodieren, PDFs byte-genau speichern.
     * Mit `$intoDocumentId` füllt sie ein Dokument im Status `wartet`.
     *
     * @return array<string, mixed> Dokument plus `duplicate`
     */
    public function storeBytes(string $bytes, string $channel, Actor $actor, int $garageId, ?int $vehicleId, ?int $intoDocumentId = null, ?int $maxBytes = null): array
    {
        $limit = $maxBytes ?? (int) $this->documentsConfig['max_bytes'];
        if (strlen($bytes) > $limit) {
            throw new DomainError('too_large', sprintf('Die Datei ist größer als %d MB.', intdiv($limit, 1024 * 1024)), ['max_bytes' => $limit]);
        }
        $mime = $this->detectMime($bytes);
        $sha256 = hash('sha256', $bytes);

        $duplicate = $this->documents->findBySha($garageId, $sha256);
        if ($duplicate !== null) {
            $this->discardWaitingAsDuplicate($intoDocumentId, $duplicate, $actor);

            return $duplicate + ['duplicate' => true];
        }

        $waiting = $intoDocumentId === null ? null : $this->waitingDocument($intoDocumentId, $garageId);
        $uid = $waiting === null ? 'd' . substr(bin2hex(random_bytes(6)), 0, 11) : (string) $waiting['uid'];
        $garageDir = $this->storageRoot() . '/' . $garageId;
        $tempDir = $garageDir . '/.tmp-' . bin2hex(random_bytes(6));
        $finalDir = $garageDir . '/' . $uid;
        $this->makeDir($tempDir);

        try {
            $processed = $this->writeFiles($bytes, $mime, $tempDir);
            if (!rename($tempDir, $finalDir)) {
                throw new DomainError('store_failed', 'Die Datei lässt sich nicht ablegen.');
            }
            $row = $this->fileRow($mime, $processed, $finalDir, $sha256, $channel, $actor, $vehicleId);

            return Transaction::join($this->pdo, function () use ($waiting, $row, $garageId, $uid, $actor, $channel, $sha256, $mime, $vehicleId): array {
                if ($waiting === null) {
                    $documentId = $this->documents->insert(['garage_id' => $garageId, 'uid' => $uid] + $row);
                } else {
                    $documentId = (int) $waiting['id'];
                    // Das Fahrzeug eines wartenden Dokuments bleibt, sonst käme es durch den Upload zu keinem anderen.
                    unset($row['vehicle_id'], $row['actor']);
                    $this->documents->update($documentId, $garageId, $row);
                }
                $this->audit->record($actor, 'document.store', sprintf('documents:%d', $documentId), $garageId, null, ['channel' => $channel, 'sha256' => $sha256, 'mime' => $mime], $vehicleId);

                return ($this->documents->findById($documentId) ?? throw new DomainError('store_failed', 'Das Dokument lässt sich nicht lesen.')) + ['duplicate' => false];
            });
        } catch (Throwable $error) {
            $this->removeDir($tempDir);
            $this->removeDir($finalDir);

            throw $error;
        }
    }

    /**
     * Metadaten setzen: Typ (Pflicht), Fahrzeug, Titel, Foto-Tag, Aufnahmedatum, erkannter Text.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> das Dokument
     */
    public function describe(int $documentId, array $input, Actor $actor): array
    {
        return Transaction::join($this->pdo, function () use ($documentId, $input, $actor): array {
            $document = $this->writableDocument($documentId, $actor);
            $fields = $this->validatedMeta($input, $document, $actor);
            $this->documents->update($documentId, $document['garage_id'], $fields);
            $this->audit->record(
                $actor,
                'document.describe',
                sprintf('documents:%d', $documentId),
                $document['garage_id'],
                ['doc_type' => $document['doc_type'], 'title' => $document['title'], 'vehicle_id' => $document['vehicle_id']],
                array_diff_key($fields, ['text_content' => true]) + (isset($fields['text_content']) ? ['text_length' => mb_strlen((string) $fields['text_content'])] : []),
                $fields['vehicle_id'] ?? $document['vehicle_id']
            );

            return $this->documents->find($documentId, $actor) ?? $document;
        });
    }

    /**
     * @return array{document: array<string, mixed>, attachments: list<array{id: int, target_table: string, target_id: int, role: string}>}
     */
    public function get(int $documentId, Actor $actor): array
    {
        $document = $this->documents->find($documentId, $actor);
        if ($document === null) {
            throw $this->notFound($documentId);
        }

        return ['document' => $document, 'attachments' => $this->documents->attachmentsFor($documentId, $actor)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, ?int $vehicleId, ?string $status, ?string $docType): array
    {
        if ($vehicleId !== null) {
            $this->access->readable($vehicleId, $actor);
        }
        if ($status !== null) {
            Validate::enum('status', $status, self::LIST_STATUSES);
        }
        if ($docType !== null) {
            Validate::enum('doc_type', $docType, self::TYPES);
        }

        $documents = $this->documents->list($actor, $vehicleId, $status, $docType);
        $attachments = $this->documents->attachmentsForMany(array_map(static fn (array $document): int => $document['id'], $documents), $actor);

        return array_map(fn (array $document): array => $this->present($document) + ['attached_to' => $attachments[$document['id']] ?? []], $documents);
    }

    /** Die Felder, die Agenten und Menschen sehen; ohne Hash, Ordnername und Volltext. @param array<string, mixed> $document @return array<string, mixed> */
    public function present(array $document): array
    {
        return [
            'id' => $document['id'],
            'vehicle_id' => $document['vehicle_id'],
            'doc_type' => $document['doc_type'],
            'photo_tag' => $document['photo_tag'],
            'title' => $document['title'],
            'mime' => $document['mime'],
            'bytes' => $document['bytes'],
            'width' => $document['width'],
            'height' => $document['height'],
            'page_count' => $document['page_count'],
            'captured_on' => $document['captured_on'],
            'status' => $document['status'],
            'status_reason' => $document['status_reason'],
            'channel' => $document['channel'],
            'created_at' => $document['created_at'],
        ];
    }

    /** Signierter Link auf die Datei eines Dokuments, ohne Anmeldung nutzbar bis zum Ablauf (E3). @param array<string, mixed> $document */
    public function linkFor(array $document, string $file): string
    {
        return $this->baseUrl() . '/files/' . FileLink::sign((int) $document['id'], $file, (int) $this->documentsConfig['link_seconds']);
    }

    /** @param array<string, mixed> $document @return string|null Dateiname der Vorschau, `null` wenn es keine gibt */
    public function previewFile(array $document): ?string
    {
        if ($document['mime'] === 'image/jpeg') {
            return '400.jpg';
        }

        return $document['page_count'] > 0 ? 'pages/1.jpg' : null;
    }

    /** @param array<string, mixed> $document @return string|null Dateiname des Originals, `null` solange die Datei fehlt */
    public function originalFile(array $document): ?string
    {
        return match ($document['mime']) {
            'image/jpeg' => 'original.jpg',
            'application/pdf' => 'original.pdf',
            default => null,
        };
    }

    /**
     * Pfad einer Datei des Dokuments; nur Namen, die das Dokument wirklich hat.
     *
     * @param array<string, mixed> $document
     */
    public function filePath(array $document, string $file): string
    {
        $isKnown = in_array($file, ['original.jpg', '1568.jpg', '400.jpg', 'original.pdf'], true)
            || (preg_match(self::PAGE_FILE, $file, $parts) === 1 && (int) $parts[1] >= 1 && (int) $parts[1] <= $document['page_count']);
        $path = sprintf('%s/%d/%s/%s', $this->storageRoot(), $document['garage_id'], $document['uid'], $file);
        if (!$isKnown || !is_file($path)) {
            throw new DomainError('not_found', 'Diese Datei gibt es nicht.');
        }

        return $path;
    }

    /** Mime-Typ, mit dem eine Datei des Dokuments ausgeliefert wird. */
    public function mimeOfFile(string $file): string
    {
        return str_ends_with($file, '.pdf') ? 'application/pdf' : 'image/jpeg';
    }

    /** Nur Rollen mit Schreibrecht legen Dateien ab; der Scope `garage:files` prüft die Registry. */
    public function assertCanStore(int $garageId, Actor $actor): void
    {
        if ($this->mayStore($garageId, $actor)) {
            return;
        }
        if (($actor->roles[$garageId] ?? null) === Actor::ROLE_READONLY) {
            throw new DomainError('forbidden', 'Dieses Konto darf in der Garage nur lesen.', ['garage_id' => $garageId]);
        }

        throw new DomainError('not_found', 'Eine Garage mit dieser ID gibt es bei dir nicht. list_vehicles nennt deine Garagen.', ['garage_id' => $garageId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function describeUploaded(int $ticketId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($ticketId, $input, $actor): array {
            $ticket = $this->documents->findTicketForUser($ticketId, (int) $actor->userId);
            if ($ticket === null) {
                throw new DomainError('not_found', 'Ein Upload-Ticket mit dieser ID gibt es bei dir nicht. request_upload liefert ein neues.', ['ticket_id' => $ticketId]);
            }
            if (!$ticket['is_redeemed'] || $ticket['document_id'] === null) {
                throw Validate::invalid('ticket_id', 'Unter diesem Ticket wurde noch keine Datei hochgeladen. Erst die Datei per POST an upload_url schicken.', self::EXAMPLE);
            }

            $document = $this->documents->find($ticket['document_id'], $actor);
            if ($document === null) {
                throw $this->notFound($ticket['document_id']);
            }
            // Eine Dublette ist ein schon beschriebenes Dokument; die Angaben des Agenten überschreiben es nicht.
            $isDuplicate = $ticket['is_duplicate'];
            if (!$isDuplicate) {
                $document = $this->describe($document['id'], $input, $actor);
            }

            return $this->storeResult($document, $isDuplicate);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createWaiting(array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($input, $actor): array {
            $garageId = isset($input['garage_id']) ? (int) $input['garage_id'] : null;
            $vehicleId = isset($input['vehicle_id']) ? (int) $input['vehicle_id'] : null;
            $target = $this->resolveTarget($actor, $garageId, $vehicleId, null);

            $documentId = $this->documents->insert([
                'garage_id' => $target['garage_id'],
                'uid' => 'd' . substr(bin2hex(random_bytes(6)), 0, 11),
                'vehicle_id' => $target['vehicle_id'],
                'status' => DocumentRepository::STATUS_WAITING,
                'channel' => self::CHANNEL_WIDGET,
                'actor' => $actor->name,
                'user_id' => $actor->userId,
            ]);
            $this->audit->record($actor, 'document.create', sprintf('documents:%d', $documentId), $target['garage_id'], null, ['status' => DocumentRepository::STATUS_WAITING], $target['vehicle_id']);

            $document = $this->describe($documentId, $input, $actor);
            $upload = $this->insertTicket($actor, $target['garage_id'], $document['vehicle_id'], $documentId);

            return $this->storeResult($document, false) + ['upload' => ['ticket_id' => $upload['ticket_id'], 'upload_url' => $upload['upload_url'], 'expires_at' => $upload['expires_at']]];
        });
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function storeResult(array $document, bool $isDuplicate): array
    {
        $preview = $this->previewFile($document);
        $original = $this->originalFile($document);

        return [
            'document_id' => $document['id'],
            'status' => $document['status'],
            'duplicate' => $isDuplicate,
            'files' => [
                'preview' => $preview === null ? null : $this->linkFor($document, $preview),
                'original' => $original === null ? null : $this->linkFor($document, $original),
            ],
        ];
    }

    /** @return array{ticket_id: int, upload_url: string, expires_at: string, max_bytes: int, accepted: list<string>} */
    private function insertTicket(Actor $actor, int $garageId, ?int $vehicleId, ?int $documentId): array
    {
        $slug = bin2hex(random_bytes(16));
        $maxBytes = (int) $this->documentsConfig['max_bytes'];
        $ticket = $this->documents->insertTicket(hash('sha256', $slug), [
            'user_id' => (int) $actor->userId,
            'garage_id' => $garageId,
            'vehicle_id' => $vehicleId,
            'document_id' => $documentId,
            'max_bytes' => $maxBytes,
            'ttl_seconds' => (int) $this->documentsConfig['ticket_seconds'],
        ]);
        $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $ticket['expires_at'], new DateTimeZone('UTC'));

        return [
            'ticket_id' => $ticket['id'],
            'upload_url' => $this->baseUrl() . '/upload/' . $slug,
            'expires_at' => $expiresAt === false ? $ticket['expires_at'] : $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'max_bytes' => $maxBytes,
            'accepted' => self::MIMES,
        ];
    }

    /**
     * @return array{garage_id: int, vehicle_id: int|null}
     */
    private function resolveTarget(Actor $actor, ?int $garageId, ?int $vehicleId, ?int $documentId): array
    {
        if ($documentId !== null) {
            $document = $this->writableDocument($documentId, $actor);
            if ($document['status'] !== DocumentRepository::STATUS_WAITING) {
                throw Validate::invalid('document_id', 'Nur ein Dokument im Status „wartet“ nimmt eine Datei nach.', '{"document_id": 5}');
            }

            return ['garage_id' => $document['garage_id'], 'vehicle_id' => $document['vehicle_id']];
        }
        if ($vehicleId !== null) {
            $vehicle = $this->access->readable($vehicleId, $actor);
            $this->assertCanStore($vehicle['garage_id'], $actor);

            return ['garage_id' => $vehicle['garage_id'], 'vehicle_id' => $vehicleId];
        }
        if ($garageId !== null) {
            $this->assertCanStore($garageId, $actor);

            return ['garage_id' => $garageId, 'vehicle_id' => null];
        }

        return ['garage_id' => $this->onlyStorableGarage($actor), 'vehicle_id' => null];
    }

    private function onlyStorableGarage(Actor $actor): int
    {
        $storable = array_values(array_filter($actor->garageIds(), fn (int $id): bool => $this->mayStore($id, $actor)));
        if (count($storable) === 1) {
            return $storable[0];
        }
        if ($storable === []) {
            throw new DomainError($actor->garageIds() === [] ? 'not_found' : 'forbidden', 'Dieses Konto darf in keiner Garage Belege ablegen.');
        }

        $names = [];
        foreach ((new GarageRepository($this->pdo))->listForUser((int) $actor->userId) as $garage) {
            if (in_array($garage['id'], $storable, true)) {
                $names[] = sprintf('%d („%s“)', $garage['id'], $garage['name']);
            }
        }

        throw new DomainError(
            'invalid_value',
            'Du kannst in mehreren Garagen ablegen, daher ist vehicle_id oder garage_id nötig: ' . implode(', ', $names) . '. Beispiel: {"garage_id": ' . $storable[0] . '}',
            ['field' => 'garage_id']
        );
    }

    private function mayStore(int $garageId, Actor $actor): bool
    {
        return in_array($actor->roles[$garageId] ?? null, [Actor::ROLE_OWNER, Actor::ROLE_MEMBER], true);
    }

    /** @return array<string, mixed> */
    private function writableDocument(int $documentId, Actor $actor): array
    {
        $document = $this->documents->find($documentId, $actor, true);
        if ($document === null) {
            throw $this->notFound($documentId);
        }
        $this->assertCanStore($document['garage_id'], $actor);

        return $document;
    }

    /** @return array<string, mixed>|null */
    private function waitingDocument(int $documentId, int $garageId): ?array
    {
        $document = $this->documents->findById($documentId);
        $isWaiting = $document !== null && $document['garage_id'] === $garageId && $document['status'] === DocumentRepository::STATUS_WAITING;

        return $isWaiting ? $document : null;
    }

    /** @param array<string, mixed> $duplicate */
    private function discardWaitingAsDuplicate(?int $waitingId, array $duplicate, Actor $actor): void
    {
        if ($waitingId === null || $waitingId === $duplicate['id']) {
            return;
        }
        $waiting = $this->waitingDocument($waitingId, $duplicate['garage_id']);
        if ($waiting === null) {
            return;
        }

        $this->documents->update($waitingId, $duplicate['garage_id'], [
            'status' => DocumentRepository::STATUS_DISCARDED,
            'status_reason' => sprintf('Dublette von #%d', $duplicate['id']),
        ]);
        $this->audit->record($actor, 'document.discard', sprintf('documents:%d', $waitingId), $duplicate['garage_id'], null, ['reason' => sprintf('Dublette von #%d', $duplicate['id'])], $waiting['vehicle_id']);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $document
     * @return array<string, mixed> Spalten für `update`
     */
    private function validatedMeta(array $input, array $document, Actor $actor): array
    {
        $fields = ['doc_type' => Validate::enum('doc_type', $input['doc_type'] ?? null, self::TYPES)];
        if (array_key_exists('photo_tag', $input) && $input['photo_tag'] !== null) {
            if ($fields['doc_type'] !== 'foto') {
                throw Validate::invalid('photo_tag', 'Ein Foto-Tag gibt es nur bei doc_type „foto“.', '{"doc_type": "foto", "photo_tag": "tacho"}');
            }
            $fields['photo_tag'] = Validate::enum('photo_tag', $input['photo_tag'], self::TAGS);
        } elseif ($fields['doc_type'] !== 'foto') {
            $fields['photo_tag'] = null;
        }
        if (array_key_exists('title', $input) && $input['title'] !== null) {
            $fields['title'] = $input['title'] === '' ? '' : Validate::text('title', $input['title'], 1, self::MAX_TITLE, self::EXAMPLE);
        }
        if (array_key_exists('captured_on', $input) && $input['captured_on'] !== null) {
            $fields['captured_on'] = Validate::date('captured_on', $input['captured_on'], '{"captured_on": "2026-03-14"}');
        }
        if (array_key_exists('text', $input) && $input['text'] !== null) {
            $fields['text_content'] = self::validText($input['text']);
        }
        if (array_key_exists('vehicle_id', $input) && $input['vehicle_id'] !== null) {
            $fields['vehicle_id'] = $this->vehicleInGarage((int) $input['vehicle_id'], $document['garage_id'], $actor);
        }

        return $fields;
    }

    private function vehicleInGarage(int $vehicleId, int $garageId, Actor $actor): int
    {
        $vehicle = $this->access->readable($vehicleId, $actor);
        if ($vehicle['garage_id'] !== $garageId) {
            throw new DomainError('not_found', 'Ein Fahrzeug mit dieser ID gibt es in der Garage dieses Dokuments nicht. list_vehicles zeigt die vorhandenen.', ['vehicle_id' => $vehicleId]);
        }

        return $vehicleId;
    }

    /** Erkannter Text: mehrzeilig, Zeilenenden vereinheitlicht, leer heißt „kein Text“. */
    private static function validText(mixed $raw): ?string
    {
        $text = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
        $isText = mb_check_encoding($text, 'UTF-8') && preg_match(self::TEXT_CONTROL_CHARACTERS, $text) !== 1;
        if (!$isText || mb_strlen($text) > self::MAX_TEXT) {
            throw Validate::invalid('text', sprintf('Erwartet wird Text mit höchstens %d Zeichen.', self::MAX_TEXT), '{"text": "Ölwechsel 89,00 EUR"}');
        }

        return $text === '' ? null : $text;
    }

    private function detectMime(string $bytes): string
    {
        $mime = (new Finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mime === false || !in_array($mime, self::MIMES, true)) {
            throw new DomainError(
                'type_rejected',
                sprintf('Der Dateityp „%s“ ist nicht erlaubt. Erlaubt: JPEG, PNG, WebP, GIF, PDF.', $mime === false ? 'unbekannt' : $mime),
                ['detected' => $mime === false ? null : $mime]
            );
        }

        return $mime;
    }

    /** @return array{width: int|null, height: int|null, captured_on: string|null, page_count: int} */
    private function writeFiles(string $bytes, string $mime, string $tempDir): array
    {
        if ($mime === 'application/pdf') {
            if (file_put_contents($tempDir . '/original.pdf', $bytes) === false) {
                throw new DomainError('store_failed', 'Die Datei lässt sich nicht schreiben.');
            }

            return ['width' => null, 'height' => null, 'captured_on' => null, 'page_count' => 0];
        }

        $image = $this->images->process($bytes, $mime, $tempDir);

        return ['width' => $image['width'], 'height' => $image['height'], 'captured_on' => $image['captured_on'], 'page_count' => 1];
    }

    /**
     * @param array{width: int|null, height: int|null, captured_on: string|null, page_count: int} $processed
     * @return array<string, mixed>
     */
    private function fileRow(string $mime, array $processed, string $finalDir, string $sha256, string $channel, Actor $actor, ?int $vehicleId): array
    {
        $isPdf = $mime === 'application/pdf';

        return [
            'vehicle_id' => $vehicleId,
            'mime' => $isPdf ? 'application/pdf' : 'image/jpeg',
            'bytes' => (int) filesize($finalDir . '/original.' . ($isPdf ? 'pdf' : 'jpg')),
            'sha256' => $sha256,
            'width' => $processed['width'],
            'height' => $processed['height'],
            'page_count' => $processed['page_count'],
            'captured_on' => $processed['captured_on'],
            'status' => DocumentRepository::STATUS_UNASSIGNED,
            'channel' => $channel,
            'actor' => $actor->name,
            'user_id' => $actor->userId,
        ];
    }

    private function notFound(int $documentId): DomainError
    {
        return new DomainError('not_found', 'Ein Dokument mit dieser ID gibt es in deinen Garagen nicht. list_documents zeigt die vorhandenen.', ['document_id' => $documentId]);
    }

    private function baseUrl(): string
    {
        return (string) $this->config['base_url'] . (string) $this->config['base_path'];
    }

    private function storageRoot(): string
    {
        return (string) $this->config['storage_dir'] . '/documents';
    }

    private function makeDir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DomainError('store_failed', 'Der Ordner für Belege lässt sich nicht anlegen.');
        }
    }

    private function removeDir(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
