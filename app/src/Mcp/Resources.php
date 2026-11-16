<?php

declare(strict_types=1);

namespace Garage\Mcp;

/** `garage://`-Resources: in Meilenstein 1 ist der Katalog leer, die Klasse hält die Protokollform bereit. */
final class Resources
{
    /** @return list<array<string, string>> */
    public function list(): array
    {
        return [];
    }

    /** @return list<array<string, string>> */
    public function templates(): array
    {
        return [];
    }

    /** @return list<array{uri: string, mimeType: string, text: string}> */
    public function read(string $uri): array
    {
        throw self::unknown($uri);
    }

    // -32602 statt -32002: ab Protokoll 2026-07-28 ist -32002 für Resources verboten.
    private static function unknown(string $uri): ProtocolError
    {
        return new ProtocolError(
            ProtocolError::INVALID_PARAMS,
            sprintf('Resource „%s“ ist unbekannt. resources/list nennt die gültigen Adressen (zurzeit gibt es keine).', $uri),
            400,
            ['uri' => $uri]
        );
    }
}
