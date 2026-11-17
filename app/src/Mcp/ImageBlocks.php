<?php

declare(strict_types=1);

namespace Garage\Mcp;

use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;

/** Aus DISPATCH (ADR-005), angepasst: Bild-Blöcke für Tool-Ergebnisse, höchstens 6 je Antwort, Kante ≤ 1568 px. */
final class ImageBlocks
{
    public const MAX_BLOCKS = 6;
    public const HALF_TOP = 'oben';
    public const HALF_BOTTOM = 'unten';

    private const OVERLAP_SHARE = 0.05;
    private const HALF_QUALITY = 85;

    /**
     * Foto: `1568.jpg`. PDF mit Seitenbildern: die Seite `$page`, sonst die ersten Seiten bis `$max`. `$half` schneidet
     * eine Hälfte mit 5 % Überlappung aus, damit kleine Schrift groß genug ankommt. Ohne darstellbare Datei keine Blöcke.
     *
     * @param array<string, mixed> $document
     * @return array{blocks: list<array{type: string, data: string, mimeType: string}>, skipped: list<int>} `skipped` = Seiten ohne Block (Grenze)
     */
    public static function forDocument(DocumentService $documents, array $document, ?int $page, ?string $half, int $max = self::MAX_BLOCKS): array
    {
        $files = self::filesFor($document, $page);
        $blocks = [];
        $skipped = [];
        foreach ($files as $pageNumber => $file) {
            $block = count($blocks) < $max ? self::block($documents, $document, $file, $half) : null;
            if ($block === null) {
                $skipped[] = $pageNumber;
                continue;
            }
            $blocks[] = $block;
        }

        return ['blocks' => $blocks, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<int, string> Seitennummer (1 beim Foto) → Dateiname
     */
    private static function filesFor(array $document, ?int $page): array
    {
        if ($document['mime'] === 'image/jpeg') {
            return [1 => '1568.jpg'];
        }
        if ($document['mime'] !== 'application/pdf' || $document['page_count'] < 1) {
            return [];
        }
        if ($page !== null) {
            if ($page < 1 || $page > $document['page_count']) {
                throw new DomainError('invalid_value', sprintf('Feld „page“: Das Dokument hat %d Seiten. Beispiel: {"document_id": 5, "page": 1}', $document['page_count']), ['field' => 'page']);
            }

            return [$page => 'pages/' . $page . '.jpg'];
        }

        $files = [];
        for ($pageNumber = 1; $pageNumber <= $document['page_count']; $pageNumber++) {
            $files[$pageNumber] = 'pages/' . $pageNumber . '.jpg';
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{type: string, data: string, mimeType: string}|null
     */
    private static function block(DocumentService $documents, array $document, string $file, ?string $half): ?array
    {
        try {
            $bytes = file_get_contents($documents->filePath($document, $file));
        } catch (DomainError) {
            return null;
        }
        if ($bytes === false) {
            return null;
        }
        if ($half !== null) {
            $bytes = self::cropHalf($bytes, $half) ?? $bytes;
        }

        return ['type' => 'image', 'data' => base64_encode($bytes), 'mimeType' => 'image/jpeg'];
    }

    private static function cropHalf(string $bytes, string $half): ?string
    {
        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $overlap = (int) round($height * self::OVERLAP_SHARE);
        $halfHeight = intdiv($height, 2);
        $cropHeight = $halfHeight + $overlap;
        $top = $half === self::HALF_BOTTOM ? $height - $cropHeight : 0;

        $cropped = imagecrop($image, ['x' => 0, 'y' => $top, 'width' => $width, 'height' => $cropHeight]);
        if ($cropped === false) {
            return null;
        }

        ob_start();
        imagejpeg($cropped, null, self::HALF_QUALITY);

        return (string) ob_get_clean();
    }
}
