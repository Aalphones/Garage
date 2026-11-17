<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\FileLink;
use Garage\Db\Connection;
use Garage\Domain\DocumentRepository;
use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;
use Garage\Http\Request;
use Garage\Http\Response;

/** `GET /garage/files/{slug}`: liefert eine Datei über einen signierten Link aus; ohne Anmeldung, der Link ist die Berechtigung (E3). */
final class FileController
{
    private const MAX_AGE_SECONDS = 600;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function show(Request $request, string $slug): never
    {
        try {
            $link = FileLink::verify($slug);
            // Ohne Akteur: die Signatur hat die Berechtigung schon geprüft. Verworfene Belege bleiben für Menschen einsehbar.
            $document = (new DocumentRepository(Connection::open()))->findById($link['document_id']);
            if ($document === null) {
                throw new DomainError('not_found', 'Der Link ist ungültig oder abgelaufen.');
            }
            $service = new DocumentService(Connection::open(), $this->config);
            $path = $service->filePath($document, $link['file']);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::file($path, $service->mimeOfFile($link['file']), [
            'Content-Disposition' => sprintf('inline; filename="%s-%s"', $document['uid'], str_replace('/', '-', $link['file'])),
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, max-age=' . self::MAX_AGE_SECONDS,
        ], $request->method() !== 'HEAD');
    }
}
