<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Suche über Ereignisse, Positionen, Teile, Notizen und offene Klärfälle der eigenen Garagen. */
final class SearchService
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;
    private const PER_SOURCE = 10;
    private const TOTAL = 30;
    private const SNIPPET = 160;
    private const EXAMPLE = '{"query": "Ölfilter", "vehicle_id": 12}';

    private VehicleAccess $access;
    private SearchRepository $search;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->search = new SearchRepository($pdo);
    }

    /**
     * Höchstens 10 Treffer je Quelle und 30 zusammen; Reihenfolge: Ereignisse, Positionen, Teile, Notizen, Klärfälle.
     *
     * @return list<array{type: string, id: int, vehicle_id: int|null, title: string, snippet: string, event_id?: int}>
     */
    public function search(mixed $query, ?int $vehicleId, Actor $actor): array
    {
        $text = Validate::text('query', $query, self::MIN_LENGTH, self::MAX_LENGTH, self::EXAMPLE);
        if ($vehicleId !== null) {
            $this->access->readable($vehicleId, $actor);
        }
        $like = SearchRepository::like($text);

        $sources = [
            'event' => $this->search->events($like, $vehicleId, self::PER_SOURCE, $actor),
            'event_item' => $this->search->eventItems($like, $vehicleId, self::PER_SOURCE, $actor),
            'part' => $this->search->parts($like, $vehicleId, self::PER_SOURCE, $actor),
            'note' => $this->search->notes($like, $vehicleId, self::PER_SOURCE, $actor),
            'open_item' => $this->search->openItems($like, $vehicleId, self::PER_SOURCE, $actor),
        ];

        $hits = [];
        foreach ($sources as $type => $rows) {
            foreach ($rows as $row) {
                $hit = [
                    'type' => $type,
                    'id' => $row['id'],
                    'vehicle_id' => $row['vehicle_id'],
                    'title' => $row['title'],
                    'snippet' => self::snippet($row['texts'], $text),
                ];
                if (isset($row['event_id'])) {
                    $hit['event_id'] = $row['event_id'];
                }
                $hits[] = $hit;
            }
        }

        return array_slice($hits, 0, self::TOTAL);
    }

    /**
     * Bis zu 160 Zeichen um die erste Fundstelle; die Datenbank findet auch ohne Rücksicht auf Akzente, dann steht der Anfang des ersten Texts da.
     *
     * @param list<string> $texts
     */
    private static function snippet(array $texts, string $query): string
    {
        foreach ($texts as $text) {
            $position = $text === '' ? false : mb_stripos($text, $query);
            if ($position === false) {
                continue;
            }

            return self::cut($text, $position, mb_strlen($query));
        }
        foreach ($texts as $text) {
            if ($text !== '') {
                return self::cut($text, 0, 0);
            }
        }

        return '';
    }

    private static function cut(string $text, int $position, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $position = min($position, max(0, mb_strlen($text) - 1));
        $start = max(0, $position - (int) floor((self::SNIPPET - $length) / 2));
        $slice = mb_substr($text, $start, self::SNIPPET);

        return ($start > 0 ? '…' : '') . $slice . ($start + self::SNIPPET < mb_strlen($text) ? '…' : '');
    }
}
