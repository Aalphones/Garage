<?php

declare(strict_types=1);

namespace Garage\Domain;

/** Darstellungs-Bausteine der Akte: deutsche Datums-, Zahlen- und Geldformate, Herkunft in Klammern, Markdown-Tabellen. */
final class DossierFormat
{
    public const EMPTY_SECTION = 'Noch nichts erfasst.';
    public const APPROXIMATE = '≈';
    private const NONE = '–';

    private const SOURCE_LABELS = [
        'beleg' => 'Beleg',
        'nutzer' => 'Nutzer',
        'recherche' => 'Recherche',
        'agent' => 'Agent',
        'werkstatt' => 'Werkstatt',
    ];
    private const CONFIDENCE_LABELS = [
        'bestaetigt' => 'bestätigt',
        'wahrscheinlich' => 'wahrscheinlich',
        'unsicher' => 'unsicher',
    ];
    private const MAX_HEADING_LEVEL = 6;

    /** `YYYY-MM-DD` (auch mit Uhrzeit) als `TT.MM.JJJJ`. */
    public static function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return self::NONE;
        }
        [$year, $month, $day] = explode('-', substr($date, 0, 10));

        return sprintf('%s.%s.%s', $day, $month, $year);
    }

    public static function number(int|float|string $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    /** Dezimalzahl mit Komma, ohne überflüssige Nullen am Ende (6,50 → 6,5). */
    public static function decimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    /** Euro-Text aus dem Service (`"1288.70"`) als `1.288,70 €`. */
    public static function euro(?string $euro): string
    {
        if ($euro === null) {
            return self::NONE;
        }

        return number_format((float) $euro, 2, ',', '.') . ' €';
    }

    /** `(Beleg, bestätigt)`; bei einer Adresse als Quelle steht sie am Ende. */
    public static function provenance(string $source, ?string $sourceUrl, string $confidence): string
    {
        $parts = [self::SOURCE_LABELS[$source] ?? $source, self::CONFIDENCE_LABELS[$confidence] ?? $confidence];
        if ($sourceUrl !== null && $sourceUrl !== '') {
            $parts[] = $sourceUrl;
        }

        return '(' . implode(', ', $parts) . ')';
    }

    /** @param array<string, mixed> $record Zeile mit den Schlüsseln source, source_url?, confidence */
    public static function provenanceOf(array $record): string
    {
        return self::provenance((string) $record['source'], isset($record['source_url']) ? (string) $record['source_url'] : null, (string) $record['confidence']);
    }

    /** Eine Tabellenzelle: `|` maskiert, Zeilenumbrüche zu Leerzeichen. */
    public static function cell(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return self::NONE;
        }

        return str_replace('|', '\|', trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $text)));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|null>> $rows
     * @return list<string> Markdown-Zeilen
     */
    public static function table(array $headers, array $rows): array
    {
        $lines = [
            '| ' . implode(' | ', $headers) . ' |',
            '|' . str_repeat(' --- |', count($headers)),
        ];
        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', array_map(self::cell(...), $row)) . ' |';
        }

        return $lines;
    }

    /** Überschriften eines Notiztextes tiefer setzen (`#` → `###`), damit sie die Gliederung der Akte nicht sprengen; Codeblöcke bleiben unberührt. */
    public static function shiftHeadings(string $markdown, int $levels): string
    {
        $isInCodeBlock = false;
        $lines = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $isInCodeBlock = !$isInCodeBlock;
            }
            if (!$isInCodeBlock && preg_match('/^(#{1,6})(\s.*)$/', $line, $match) === 1) {
                $line = str_repeat('#', min(self::MAX_HEADING_LEVEL, strlen($match[1]) + $levels)) . $match[2];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** `profiltiefe` → `Profiltiefe`, `batterie_spannung` → `Batterie spannung`. */
    public static function humanize(string $key): string
    {
        return mb_strtoupper(mb_substr($key, 0, 1)) . str_replace('_', ' ', mb_substr($key, 1));
    }
}
