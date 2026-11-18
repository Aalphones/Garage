<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Db\Connection;
use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;
use Garage\Http\Request;
use Garage\Http\Response;

/**
 * `POST /garage/upload/{slug}`: nimmt die Datei zu einem Upload-Ticket entgegen (E4, ADR-012). Der Slug ist die Berechtigung,
 * eine Anmeldung gibt es hier nicht. Der Body ist die Datei selbst (`Content-Type` beliebig) oder multipart mit dem Feld `file`.
 */
final class UploadController
{
    private const SLUG_PATTERN = '/^[0-9a-f]{32}$/';
    /** Aufschlag für Multipart-Grenzen und Kopfzeilen über der erlaubten Dateigröße. */
    private const MULTIPART_OVERHEAD_BYTES = 65536;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** Preflight für das Claude-Widget, das aus fremdem Ursprung hochlädt (E15). Nur diese Route bekommt CORS. */
    public function options(): never
    {
        Response::empty(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '600',
        ]);
    }

    public function upload(Request $request, string $slug): never
    {
        // Vor allem anderen, damit auch Fehlerantworten für den fremden Ursprung lesbar sind.
        header('Access-Control-Allow-Origin: *');

        try {
            if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                throw new DomainError('ticket_not_found', 'Diese Upload-Adresse kennt GARAGE nicht. request_upload liefert eine neue.');
            }
            $result = (new DocumentService(Connection::open(), $this->config))->redeemTicket($slug, $this->bytes($request));
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($result, 201);
    }

    private function bytes(Request $request): string
    {
        $maxBytes = (int) $this->config['documents']['max_bytes'];
        $isMultipart = $request->mediaType() === 'multipart/form-data';
        if ($request->contentLength() > $maxBytes + self::MULTIPART_OVERHEAD_BYTES) {
            throw new DomainError('too_large', sprintf('Die Datei ist größer als %d MB.', intdiv($maxBytes, 1024 * 1024)), ['max_bytes' => $maxBytes]);
        }

        $bytes = $isMultipart ? $this->multipartBytes($request) : $request->body();
        if ($bytes === '') {
            throw new DomainError('invalid_value', 'Der Body ist leer. Die Datei als Body senden (curl --data-binary @datei) oder als Formularfeld „file“ (curl -F file=@datei).');
        }

        return $bytes;
    }

    private function multipartBytes(Request $request): string
    {
        $file = $request->files()['file'] ?? null;
        // Über post_max_size bleibt $_FILES leer, obwohl der Body angekommen ist.
        if (!is_array($file) && $request->contentLength() > 0) {
            throw new DomainError('too_large', 'Die Datei ist größer, als der Server annimmt.');
        }
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
            throw new DomainError('invalid_value', 'Das Formularfeld „file“ fehlt. Beispiel: curl -F file=@rechnung.pdf <upload_url>');
        }
        if (in_array($file['error'] ?? UPLOAD_ERR_OK, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new DomainError('too_large', 'Die Datei ist größer, als der Server annimmt.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new DomainError('invalid_value', 'Die Datei kam nicht vollständig an. Bitte noch einmal senden.');
        }

        return (string) file_get_contents($file['tmp_name']);
    }
}
