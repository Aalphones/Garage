<?php

declare(strict_types=1);

namespace Garage\Mcp;

/** Ergebnis eines Tools: ein Textblock mit kompaktem JSON, dazu optional Bild-Blöcke (ab Meilenstein 3). */
final readonly class ToolResult
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @param list<array<string, mixed>> $content */
    private function __construct(private array $content, private bool $isError)
    {
    }

    /** @param list<array<string, mixed>> $images fertige MCP-Bild-Blöcke */
    public static function json(mixed $data, array $images = []): self
    {
        return new self([self::text($data), ...$images], false);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, array $details = []): self
    {
        return new self([self::text(['error' => $code, 'message' => $message, 'details' => (object) $details])], true);
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    public function toArray(): array
    {
        return ['content' => $this->content, 'isError' => $this->isError];
    }

    /** @return array{type: string, text: string} */
    private static function text(mixed $data): array
    {
        return ['type' => 'text', 'text' => json_encode($data, self::JSON_FLAGS)];
    }
}
