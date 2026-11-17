<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;
use Garage\Http\Request;
use Garage\Http\Response;

/** Belege in der Oberfläche: listen, ansehen, hochladen, nachreichen, ändern, verwerfen. Nur mit Anmeldung eines Menschen (ADR-012). */
final class DocumentController
{
    /** Aufschlag über der Dateigröße: höchstens 20 Seitenbilder zu je 4 MB plus Multipart-Grenzen. */
    private const MULTIPART_OVERHEAD_BYTES = 20 * 4194304 + 65536;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        try {
            $documents = $this->service()->listForHumans(
                $actor,
                $this->intOrNull($request->query('vehicle_id')),
                $request->query('status'),
                $request->query('doc_type')
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json(['documents' => $documents]);
    }

    public function show(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $document = $this->service()->details($id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($document);
    }

    public function create(Request $request): never
    {
        $this->upload($request, null);
    }

    public function uploadFile(Request $request, int $id): never
    {
        $this->upload($request, $id);
    }

    public function update(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $document = $this->service()->updateByHuman($id, $request->json(), $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($document);
    }

    public function discard(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $document = $this->service()->discard($id, $request->json()['reason'] ?? null, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($document);
    }

    public function restore(Request $request, int $id): never
    {
        $actor = ApiAuth::user($request);

        try {
            $document = $this->service()->restore($id, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($document);
    }

    private function upload(Request $request, ?int $intoDocumentId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $maxBytes = (int) $this->config['documents']['max_bytes'];
            if ($request->contentLength() > $maxBytes + self::MULTIPART_OVERHEAD_BYTES) {
                throw new DomainError('too_large', sprintf('Die Datei ist größer als %d MB.', intdiv($maxBytes, 1024 * 1024)), ['max_bytes' => $maxBytes]);
            }
            if ($request->mediaType() !== 'multipart/form-data') {
                throw new DomainError('invalid_value', 'Erwartet wird multipart/form-data mit dem Feld „file“ (und bei PDFs „pages[]“).', ['field' => 'file']);
            }

            $form = $request->form();
            $result = $this->service()->storeWebUpload(
                $this->fileBytes($request, 'file'),
                $this->pageBytes($request),
                $this->intOrNull($form['garage_id'] ?? null),
                $this->intOrNull($form['vehicle_id'] ?? null),
                isset($form['doc_type']) && is_string($form['doc_type']) && $form['doc_type'] !== '' ? $form['doc_type'] : null,
                $actor,
                $intoDocumentId
            );
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result, $intoDocumentId === null ? 201 : 200);
    }

    private function fileBytes(Request $request, string $field): string
    {
        $file = $request->files()[$field] ?? null;
        // Über post_max_size bleibt $_FILES leer, obwohl der Body angekommen ist; darunter fehlt das Feld wirklich.
        if (!is_array($file) && $request->contentLength() > self::postMaxBytes()) {
            throw new DomainError('too_large', 'Die Datei ist größer, als der Server annimmt.');
        }
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
            throw new DomainError('invalid_value', 'Das Formularfeld „file“ fehlt.', ['field' => $field]);
        }

        return self::readUpload((string) $file['tmp_name'], (int) ($file['error'] ?? UPLOAD_ERR_OK));
    }

    /** @return list<string> JPEG-Bytes je Seite in der Reihenfolge der Formularfelder `pages[]` */
    private function pageBytes(Request $request): array
    {
        $pages = $request->files()['pages'] ?? null;
        if (!is_array($pages)) {
            return [];
        }
        $temps = is_array($pages['tmp_name'] ?? null) ? array_values($pages['tmp_name']) : [$pages['tmp_name'] ?? null];
        $errors = is_array($pages['error'] ?? null) ? array_values($pages['error']) : [$pages['error'] ?? UPLOAD_ERR_OK];
        if (count($temps) > DocumentService::MAX_PAGES) {
            throw new DomainError('invalid_value', sprintf('Feld „pages“: höchstens %d Seiten.', DocumentService::MAX_PAGES), ['field' => 'pages']);
        }

        $bytes = [];
        foreach ($temps as $index => $temp) {
            if (!is_string($temp)) {
                continue;
            }
            $bytes[] = self::readUpload($temp, (int) ($errors[$index] ?? UPLOAD_ERR_OK));
        }

        return $bytes;
    }

    private static function readUpload(string $tempPath, int $error): string
    {
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new DomainError('too_large', 'Die Datei ist größer, als der Server annimmt.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainError('invalid_value', 'Die Datei kam nicht vollständig an. Bitte noch einmal senden.');
        }

        return (string) file_get_contents($tempPath);
    }

    private static function postMaxBytes(): int
    {
        $raw = trim((string) ini_get('post_max_size'));
        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? $value : null);
    }

    private function service(): DocumentService
    {
        return new DocumentService(Connection::open(), $this->config);
    }
}
