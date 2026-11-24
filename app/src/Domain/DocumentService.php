<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Finfo;
use Garage\Auth\FileLink;
use Garage\Auth\Scopes;
use Garage\Db\Transaction;
use Garage\Util\HttpClient;
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

    /** Anhangsziele (E5): Name im Tool → Tabelle. Alle außer `vehicles` tragen `source_document_id`. */
    public const TARGETS = [
        'fahrzeug' => 'vehicles',
        'ereignis' => 'events',
        'schaden' => 'damages',
        'rueckruf' => 'recalls',
        'reifensatz' => 'tire_sets',
        'teil' => 'parts',
        'messwert' => 'measurements',
        'kilometerstand' => 'odometer_readings',
        'zulassung' => 'registrations',
        'vertrag' => 'contracts',
        'vorgang' => 'contract_events',
        'zubehoer' => 'accessories',
        'plakette' => 'permits',
        'tankvorgang' => 'fuel_logs',
        'ausgabe' => 'expenses',
        'bewertung' => 'valuations',
    ];
    public const ROLES = ['beleg', 'foto', 'anhang', 'titelbild'];

    public const CHANNEL_TICKET = 'ticket';
    public const CHANNEL_WIDGET = 'widget';
    public const CHANNEL_CHATGPT = 'chatgpt';
    public const CHANNEL_URL = 'url';
    public const CHANNEL_WEB = 'web';
    public const MAX_PAGES = 20;

    private const PAGE_EDGE = 1568;
    private const MAX_PAGE_BYTES = 4194304;

    private const MAX_TEXT = 200000;
    private const MAX_TITLE = 200;
    private const TEXT_CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    private const EXAMPLE = '{"ticket_id": 7, "vehicle_id": 12, "doc_type": "rechnung", "title": "Rechnung Werkstatt Müller", "text": "Ölwechsel 89,00 EUR"}';
    private const PAGE_FILE = '#^pages/([0-9]{1,2})\.jpg$#';

    private DocumentRepository $documents;
    private VehicleAccess $access;
    private ContractRepository $contracts;
    private AuditRepository $audit;
    private ImageProcessor $images;
    /** @var array<string, mixed> */
    private array $documentsConfig;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
        $this->documents = new DocumentRepository($pdo);
        $this->access = new VehicleAccess($pdo);
        $this->contracts = new ContractRepository($pdo);
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
        if (isset($input['file'])) {
            if (isset($input['ticket_id'])) {
                throw new DomainError('invalid_argument', 'ticket_id und file schließen sich aus: entweder die Datei über ein Upload-Ticket hochladen oder das Dateiobjekt übergeben, nicht beides.', ['field' => 'file']);
            }
            $file = self::validFile($input['file']);

            return $this->storeFromUrl($input, $file['download_url'], self::CHANNEL_CHATGPT, $actor);
        }
        if (isset($input['ticket_id'])) {
            return $this->describeUploaded((int) $input['ticket_id'], $input, $actor);
        }

        return $this->createWaiting($input, $actor);
    }

    /**
     * `import_document_from_url` und ChatGPT-Datei-Parameter: Datei abholen, ablegen, beschreiben. Die Abholung läuft vor der
     * Transaktion, damit keine Sperre während des Netzwerkzugriffs offen bleibt.
     *
     * @param array<string, mixed> $input Felder wie bei `store_document` (ohne ticket_id)
     * @return array<string, mixed> wie `store`
     */
    public function storeFromUrl(array $input, string $url, string $channel, Actor $actor): array
    {
        Validate::enum('doc_type', $input['doc_type'] ?? null, self::TYPES);
        $stored = $this->importFromUrl(
            $url,
            $channel,
            $actor,
            isset($input['garage_id']) ? (int) $input['garage_id'] : null,
            isset($input['vehicle_id']) ? (int) $input['vehicle_id'] : null
        );

        return Transaction::run($this->pdo, function () use ($stored, $input, $actor): array {
            $document = $stored;
            // Eine Dublette ist ein schon beschriebenes Dokument; die Angaben des Agenten überschreiben es nicht.
            if (!$stored['duplicate']) {
                $document = $this->describe($stored['id'], $input, $actor);
            }

            return $this->storeResult($document, $stored['duplicate']);
        });
    }

    /**
     * Holt die Datei unter `$url` ab (SSRF-Schutz im `HttpClient`) und legt sie ab.
     *
     * @return array<string, mixed> Dokument plus `duplicate`
     */
    public function importFromUrl(string $url, string $channel, Actor $actor, ?int $garageId, ?int $vehicleId): array
    {
        $target = $this->resolveTarget($actor, $garageId, $vehicleId, null);
        $client = new HttpClient((array) $this->config['http'], $this->baseUrl());
        try {
            $response = $client->get($url, (int) $this->documentsConfig['max_bytes']);
        } catch (DomainError $error) {
            if ($error->errorCode === 'url_rejected') {
                throw new DomainError('invalid_value', $error->getMessage(), ['field' => 'url'] + $error->details);
            }

            throw $error;
        }

        return $this->storeBytes($response->body, $channel, $actor, $target['garage_id'], $target['vehicle_id']);
    }

    /**
     * Hängt ein Dokument an einen Datensatz (E5). Bei Rolle `beleg` wird `source_document_id` gesetzt, wenn die Spalte leer ist;
     * ein neues Titelbild löst das alte ab.
     *
     * @return array{attachment_id: int, result: string, source_document_set: bool}
     */
    public function attach(int $documentId, string $target, int $targetId, string $role, ?string $text, Actor $actor): array
    {
        $example = '{"document_id": 5, "target": "ereignis", "target_id": 31, "role": "beleg"}';
        $table = self::TARGETS[Validate::enum('target', $target, array_keys(self::TARGETS))];
        Validate::enum('role', $role, self::ROLES);
        $newText = $text === null ? null : self::validText($text);

        return Transaction::run($this->pdo, function () use ($documentId, $table, $targetId, $role, $text, $newText, $actor, $example): array {
            $document = $this->documents->find($documentId, $actor, true);
            if ($document === null) {
                throw $this->notFound($documentId);
            }
            if ($document['status'] === DocumentRepository::STATUS_WAITING) {
                throw Validate::invalid('document_id', 'Erst das Original ablegen: das Dokument hat noch keine Datei (Status „wartet“).', $example);
            }
            if ($document['status'] === DocumentRepository::STATUS_DISCARDED) {
                throw Validate::invalid('document_id', 'Das Dokument ist verworfen und lässt sich nicht anhängen.', $example);
            }

            $row = $this->documents->findTarget($table, $targetId, $actor);
            // Ein Garagen-Vertrag (ohne Fahrzeug) nimmt jedes Dokument seiner Garage, auch eines, das schon an einem Fahrzeug hängt.
            $isOtherVehicle = $row !== null && $row['vehicle_id'] !== null && $document['vehicle_id'] !== null && $document['vehicle_id'] !== $row['vehicle_id'];
            if ($row === null || $row['garage_id'] !== $document['garage_id'] || $isOtherVehicle) {
                throw new DomainError('not_found', 'Dieses Ziel gibt es für das Dokument nicht: es muss zum selben Fahrzeug gehören. list_events, list_documents und die anderen Lese-Tools nennen die IDs.', ['target_id' => $targetId]);
            }
            if ($row['vehicle_id'] === null) {
                $this->contracts->lockGarage($row['garage_id'], $actor);
                $this->access->assertCanWriteGarage($row['garage_id'], $actor);
            } else {
                $this->access->writable($row['vehicle_id'], $actor);
            }

            if ($role === 'titelbild' && ($table !== 'vehicles' || $document['doc_type'] !== 'foto')) {
                throw Validate::invalid('role', 'Ein Titelbild ist nur ein Foto (doc_type „foto“) und nur am Fahrzeug (target „fahrzeug“).', '{"document_id": 5, "target": "fahrzeug", "target_id": 12, "role": "titelbild"}');
            }
            if ($role === 'titelbild') {
                $this->documents->demoteCover($row['garage_id'], $row['vehicle_id'], $documentId);
            }

            $isNew = $this->documents->insertAttachment($row['garage_id'], $documentId, $table, $targetId, $role, $actor);
            $attachmentId = $this->documents->findAttachmentId($documentId, $table, $targetId, $role);
            $isSourceSet = $role === 'beleg' && $table !== 'vehicles' && $this->documents->setSourceDocument($table, $targetId, $row['garage_id'], $documentId);

            $fields = ['status' => DocumentRepository::STATUS_ASSIGNED];
            if ($document['vehicle_id'] === null && $row['vehicle_id'] !== null) {
                $fields['vehicle_id'] = $row['vehicle_id'];
            }
            if ($text !== null) {
                $fields['text_content'] = $newText;
            }
            $this->documents->update($documentId, $document['garage_id'], $fields);

            if ($isNew || $isSourceSet || $text !== null) {
                $this->audit->record(
                    $actor,
                    'document.attach',
                    sprintf('documents:%d', $documentId),
                    $document['garage_id'],
                    null,
                    ['target' => $table, 'target_id' => $targetId, 'role' => $role, 'source_document_set' => $isSourceSet, 'text_replaced' => $text !== null],
                    $row['vehicle_id']
                );
            }

            return ['attachment_id' => $attachmentId, 'result' => $isNew ? 'angelegt' : 'unveraendert', 'source_document_set' => $isSourceSet];
        });
    }

    /**
     * Belege an einem Datensatz für `get_event` und Co.; `preview_url` ist ein signierter Link (`null` ohne Vorschau).
     *
     * @return list<array{id: int, doc_type: string, title: string, role: string, preview_url: string|null}>
     */
    public function forTarget(string $table, int $targetId, Actor $actor): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'doc_type' => $row['doc_type'],
            'title' => $row['title'],
            'role' => $row['role'],
            'preview_url' => $this->previewUrl($row),
        ], $this->documents->documentsFor($table, $targetId, $actor));
    }

    /**
     * Zusammenfassung für `get_vehicle`.
     *
     * @return array{count: int, open: int, cover: array{document_id: int, preview_url: string|null}|null}
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        $cover = $this->documents->coverFor($vehicleId, $actor);

        return $this->documents->countsFor($vehicleId, $actor) + [
            'cover' => $cover === null ? null : ['document_id' => $cover['id'], 'preview_url' => $this->previewUrl($cover)],
        ];
    }

    /** @param array{id: int, mime: string|null, page_count: int} $row */
    private function previewUrl(array $row): ?string
    {
        $file = $this->previewFile($row);

        return $file === null ? null : $this->linkFor($row, $file);
    }

    /**
     * @return array{download_url: string, file_id: string}
     */
    private static function validFile(mixed $raw): array
    {
        $example = '{"file": {"download_url": "https://files.example/abc", "file_id": "file_123"}, "doc_type": "rechnung"}';
        if (!is_array($raw) || array_is_list($raw)) {
            throw Validate::invalid('file', 'file ist ein Objekt mit download_url und file_id.', $example);
        }

        return [
            'download_url' => Validate::text('file.download_url', $raw['download_url'] ?? null, 1, 2048, $example),
            'file_id' => Validate::text('file.file_id', $raw['file_id'] ?? null, 1, 200, $example),
        ];
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
     * Mit `$intoDocumentId` füllt sie ein Dokument im Status `wartet`. `$pageImages` sind die im Browser gerenderten
     * PDF-Seiten (JPEG-Bytes in Seitenreihenfolge); sie landen als `pages/<n>.jpg`.
     *
     * @param list<string> $pageImages
     * @return array<string, mixed> Dokument plus `duplicate`
     */
    public function storeBytes(string $bytes, string $channel, Actor $actor, int $garageId, ?int $vehicleId, ?int $intoDocumentId = null, ?int $maxBytes = null, array $pageImages = []): array
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
            if ($pageImages !== []) {
                $processed['page_count'] = $this->writePages($pageImages, $tempDir);
            }
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

    /**
     * Web-Upload eines Menschen (E12): die Datei, bei PDFs dazu die im Browser gerenderten Seitenbilder. Mit `$intoDocumentId`
     * wird ein Dokument im Status `wartet` gefüllt (sonst `already_has_file`). Garage aus Dokument, Fahrzeug, Angabe oder der
     * einzigen Garage mit Schreibrecht.
     *
     * @param list<string> $pageBytes JPEG-Bytes je PDF-Seite
     * @return array<string, mixed> `details` des Dokuments plus `duplicate`
     */
    public function storeWebUpload(string $bytes, array $pageBytes, ?int $garageId, ?int $vehicleId, ?string $docType, Actor $actor, ?int $intoDocumentId = null): array
    {
        $type = $docType === null ? null : Validate::enum('doc_type', $docType, self::TYPES);
        if ($bytes === '') {
            throw new DomainError('invalid_value', 'Das Formularfeld „file“ fehlt oder ist leer.', ['field' => 'file']);
        }
        if ($pageBytes !== []) {
            if (strlen($bytes) > 0 && $this->detectMime($bytes) !== 'application/pdf') {
                throw Validate::invalid('pages', 'Seitenbilder (pages[]) gibt es nur zu einem PDF.', 'curl -F file=@rechnung.pdf -F pages[]=@s1.jpg -F pages[]=@s2.jpg');
            }
            if (count($pageBytes) > self::MAX_PAGES) {
                throw Validate::invalid('pages', sprintf('Höchstens %d Seiten.', self::MAX_PAGES), 'curl -F file=@rechnung.pdf -F pages[]=@s1.jpg');
            }
            foreach ($pageBytes as $page) {
                if (strlen($page) > self::MAX_PAGE_BYTES) {
                    throw new DomainError('too_large', sprintf('Ein Seitenbild ist größer als %d MB.', intdiv(self::MAX_PAGE_BYTES, 1048576)), ['field' => 'pages']);
                }
            }
        }

        if ($intoDocumentId !== null) {
            $waiting = $this->writableDocument($intoDocumentId, $actor);
            if ($waiting['status'] !== DocumentRepository::STATUS_WAITING) {
                throw new DomainError('already_has_file', 'Dieses Dokument hat schon eine Datei; nachgereicht wird nur zu einem Dokument im Status „wartet“.', ['document_id' => $intoDocumentId]);
            }
            $target = ['garage_id' => $waiting['garage_id'], 'vehicle_id' => $waiting['vehicle_id']];
        } else {
            $target = $this->resolveTarget($actor, $garageId, $vehicleId, null);
        }

        $stored = $this->storeBytes($bytes, self::CHANNEL_WEB, $actor, $target['garage_id'], $target['vehicle_id'], $intoDocumentId, null, $pageBytes);
        if (!$stored['duplicate'] && $type !== null) {
            Transaction::join($this->pdo, function () use ($stored, $type): void {
                $this->documents->update($stored['id'], $stored['garage_id'], ['doc_type' => $type, 'photo_tag' => $type === 'foto' ? $stored['photo_tag'] : null]);
            });
        }

        return $this->details($stored['id'], $actor) + ['duplicate' => $stored['duplicate']];
    }

    /**
     * Ein Mensch ändert Typ, Fahrzeug, Titel oder Foto-Tag. Ein Dokument mit Anhängen behält sein Fahrzeug.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> `details`
     */
    public function updateByHuman(int $documentId, array $input, Actor $actor): array
    {
        $this->assertHuman($actor);
        $known = ['doc_type', 'vehicle_id', 'title', 'photo_tag'];
        if (array_intersect(array_keys($input), $known) === []) {
            throw Validate::invalid('body', 'Mindestens eines von doc_type, vehicle_id, title, photo_tag.', '{"doc_type": "rechnung", "title": "Rechnung Werkstatt Müller"}');
        }

        return Transaction::run($this->pdo, function () use ($documentId, $input, $actor): array {
            $document = $this->writableDocument($documentId, $actor);
            $fields = [];
            if (array_key_exists('doc_type', $input)) {
                $fields['doc_type'] = Validate::enum('doc_type', $input['doc_type'], self::TYPES);
            }
            $type = $fields['doc_type'] ?? $document['doc_type'];
            if (array_key_exists('photo_tag', $input) && $input['photo_tag'] !== null) {
                if ($type !== 'foto') {
                    throw Validate::invalid('photo_tag', 'Ein Foto-Tag gibt es nur bei doc_type „foto“.', '{"doc_type": "foto", "photo_tag": "tacho"}');
                }
                $fields['photo_tag'] = Validate::enum('photo_tag', $input['photo_tag'], self::TAGS);
            } elseif (array_key_exists('photo_tag', $input) || $type !== 'foto') {
                $fields['photo_tag'] = null;
            }
            if (array_key_exists('title', $input)) {
                $fields['title'] = $input['title'] === null || $input['title'] === '' ? '' : Validate::text('title', $input['title'], 1, self::MAX_TITLE, self::EXAMPLE);
            }
            if (array_key_exists('vehicle_id', $input)) {
                $vehicleId = $input['vehicle_id'] === null ? null : $this->vehicleInGarage((int) $input['vehicle_id'], $document['garage_id'], $actor);
                if ($vehicleId !== $document['vehicle_id'] && $this->documents->attachmentsFor($documentId, $actor) !== []) {
                    throw Validate::invalid('vehicle_id', 'Das Dokument hängt schon an Datensätzen dieses Fahrzeugs; erst die Anhänge lösen.', '{"vehicle_id": 12}');
                }
                $fields['vehicle_id'] = $vehicleId;
            }

            $this->documents->update($documentId, $document['garage_id'], $fields);
            $this->audit->record($actor, 'document.update', sprintf('documents:%d', $documentId), $document['garage_id'], array_intersect_key($document, $fields), $fields, $fields['vehicle_id'] ?? $document['vehicle_id']);

            return $this->details($documentId, $actor);
        });
    }

    /** Ein Mensch verwirft ein Dokument mit Grund; die Datei bleibt. */
    public function discard(int $documentId, mixed $reason, Actor $actor): array
    {
        $this->assertHuman($actor);
        $text = Validate::text('reason', $reason, 1, 200, '{"reason": "Doppelt fotografiert"}');

        return Transaction::run($this->pdo, function () use ($documentId, $text, $actor): array {
            $document = $this->writableDocument($documentId, $actor);
            if ($document['status'] === DocumentRepository::STATUS_DISCARDED) {
                throw Validate::invalid('document_id', 'Das Dokument ist schon verworfen.', '{"reason": "Doppelt fotografiert"}');
            }
            $this->documents->update($documentId, $document['garage_id'], ['status' => DocumentRepository::STATUS_DISCARDED, 'status_reason' => $text]);
            $this->audit->record($actor, 'document.discard', sprintf('documents:%d', $documentId), $document['garage_id'], ['status' => $document['status']], ['reason' => $text], $document['vehicle_id']);

            return $this->details($documentId, $actor);
        });
    }

    /** Ein Mensch holt ein verworfenes Dokument zurück; der Status folgt aus Datei und Anhängen. */
    public function restore(int $documentId, Actor $actor): array
    {
        $this->assertHuman($actor);

        return Transaction::run($this->pdo, function () use ($documentId, $actor): array {
            $document = $this->writableDocument($documentId, $actor);
            if ($document['status'] !== DocumentRepository::STATUS_DISCARDED) {
                throw Validate::invalid('document_id', 'Nur ein verworfenes Dokument lässt sich zurückholen.', '{}');
            }
            if ($document['mime'] === null) {
                $status = DocumentRepository::STATUS_WAITING;
            } else {
                $status = $this->documents->attachmentsFor($documentId, $actor) === [] ? DocumentRepository::STATUS_UNASSIGNED : DocumentRepository::STATUS_ASSIGNED;
            }
            $this->documents->update($documentId, $document['garage_id'], ['status' => $status, 'status_reason' => null]);
            $this->audit->record($actor, 'document.restore', sprintf('documents:%d', $documentId), $document['garage_id'], ['status' => DocumentRepository::STATUS_DISCARDED], ['status' => $status], $document['vehicle_id']);

            return $this->details($documentId, $actor);
        });
    }

    /**
     * Ein Dokument für die Oberfläche: Angaben, Anhänge, Text und signierte Links (Seiten, Original, Vorschau).
     *
     * @return array<string, mixed>
     */
    public function details(int $documentId, Actor $actor): array
    {
        $found = $this->get($documentId, $actor);
        $document = $found['document'];
        $original = $this->originalFile($document);
        $preview = $this->previewFile($document);
        $pages = [];
        if ($document['mime'] === 'image/jpeg') {
            $pages[] = $this->linkFor($document, '1568.jpg');
        } else {
            for ($page = 1; $page <= $document['page_count'] && $document['mime'] === 'application/pdf'; $page++) {
                $pages[] = $this->linkFor($document, 'pages/' . $page . '.jpg');
            }
        }

        return $this->present($document) + [
            'garage_id' => $document['garage_id'],
            'created_by' => $document['actor'],
            'attachments' => $found['attachments'],
            'text_content' => $document['text_content'],
            'pages' => $pages,
            'original_url' => $original === null ? null : $this->linkFor($document, $original),
            'preview_url' => $preview === null ? null : $this->linkFor($document, $preview),
            'can_edit' => $actor->via === Actor::VIA_WEB && $actor->canWrite($document['garage_id']),
        ];
    }

    /**
     * Liste für die Oberfläche: wie `list`, dazu `garage_id` und der signierte `preview_url`.
     *
     * @return list<array<string, mixed>>
     */
    public function listForHumans(Actor $actor, ?int $vehicleId, ?string $status, ?string $docType): array
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

        return array_map(fn (array $document): array => $this->present($document) + [
            'garage_id' => $document['garage_id'],
            'attached_to' => $attachments[$document['id']] ?? [],
            'preview_url' => $this->previewUrl($document),
        ], $documents);
    }

    private function assertHuman(Actor $actor): void
    {
        if ($actor->via !== Actor::VIA_WEB) {
            throw new DomainError('forbidden', 'Das ändert nur ein Mensch in der Oberfläche.');
        }
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

            $label = $document['vehicle_id'] === null ? null : $this->access->readable((int) $document['vehicle_id'], $actor)['label'];

            return $this->storeResult($document, false) + [
                'widget' => true,
                'vehicle_label' => $label,
                'doc_type' => $document['doc_type'],
                'title' => $document['title'],
                'captured_on' => $document['captured_on'],
                'upload' => ['ticket_id' => $upload['ticket_id'], 'upload_url' => $upload['upload_url'], 'expires_at' => $upload['expires_at'], 'max_bytes' => $upload['max_bytes'], 'accepted' => $upload['accepted']],
            ];
        });
    }

    /**
     * `confirm_document` (vom Widget nach dem Upload): bestätigt, dass die Datei angekommen ist.
     *
     * @return array{document_id: int, status: string, attached_to: list<array<string, mixed>>}
     */
    public function confirm(int $documentId, Actor $actor): array
    {
        $document = $this->writableDocument($documentId, $actor);
        if ($document['status'] === DocumentRepository::STATUS_WAITING) {
            throw Validate::invalid('document_id', 'Datei noch nicht angekommen. Erst hochladen, dann bestätigen; request_upload mit document_id liefert bei Bedarf eine neue Adresse.', '{"document_id": 5}');
        }

        return ['document_id' => $documentId, 'status' => $document['status'], 'attached_to' => $this->documents->attachmentsFor($documentId, $actor)];
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

    /** @param list<string> $pageImages @return int Anzahl der Seiten */
    private function writePages(array $pageImages, string $tempDir): int
    {
        $this->makeDir($tempDir . '/pages');
        foreach ($pageImages as $index => $bytes) {
            $this->images->processPage($bytes, sprintf('%s/pages/%d.jpg', $tempDir, $index + 1), self::PAGE_EDGE);
        }

        return count($pageImages);
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
