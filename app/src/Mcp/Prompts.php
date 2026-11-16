<?php

declare(strict_types=1);

namespace Garage\Mcp;

/** Prompts (Slash-Befehle in Claude Code): in Meilenstein 1 ist der Katalog leer, die Klasse hält die Protokollform bereit. */
final class Prompts
{
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>}
     */
    public function get(string $name, array $arguments): array
    {
        throw new ProtocolError(
            ProtocolError::INVALID_PARAMS,
            sprintf('Prompt „%s“ ist unbekannt. prompts/list nennt die gültigen (zurzeit gibt es keine).', $name),
            400,
            ['name' => $name]
        );
    }
}
