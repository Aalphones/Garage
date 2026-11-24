<?php

declare(strict_types=1);

namespace Garage\Domain;

/** Bausteine für iCalendar nach RFC 5545: Maskieren, Falten, ganztägige Termine, Kalender. Reine Textarbeit. */
final class Ical
{
    private const CRLF = "\r\n";
    private const MAX_LINE_OCTETS = 75;

    /** Maskiert Text nach RFC 5545 3.3.11: Backslash, Semikolon, Komma, Zeilenumbruch. */
    public static function escape(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $normalized);
    }

    /**
     * Faltet eine Zeile nach 75 Oktetten (RFC 5545 3.1): Folgezeilen beginnen mit einem Leerzeichen, das mitzählt.
     * Ein UTF-8-Zeichen wird nie zerteilt — der Schnitt rückt vor dessen erstes Byte.
     */
    public static function fold(string $line): string
    {
        $parts = [];
        $limit = self::MAX_LINE_OCTETS;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $parts[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = self::MAX_LINE_OCTETS - 1;
        }
        $parts[] = $line;

        return implode(self::CRLF . ' ', $parts);
    }

    /**
     * Ganztägiger Termin; `$stamp` ist der Abrufzeitpunkt in UTC (`JJJJMMTTTHHMMSSZ`).
     *
     * @return list<string> ungefaltete Zeilen
     */
    public static function event(string $uid, string $date, string $summary, string $description, string $stamp): array
    {
        $start = str_replace('-', '', $date);
        $end = str_replace('-', '', DueCalculator::addDays($date, 1));

        return [
            'BEGIN:VEVENT',
            'UID:' . self::escape($uid),
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . $start,
            'DTEND;VALUE=DATE:' . $end,
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape($description),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];
    }

    /**
     * Ganzer Kalender, jede Zeile gefaltet und mit CRLF beendet.
     *
     * @param list<list<string>> $events Ausgaben von `event`
     */
    public static function calendar(string $name, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//GARAGE//Kalender//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];
        foreach ($events as $event) {
            array_push($lines, ...$event);
        }
        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map(self::fold(...), $lines)) . self::CRLF;
    }
}
