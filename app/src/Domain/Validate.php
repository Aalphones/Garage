<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Prüfer für Eingabewerte. Jeder wirft `invalid_value` mit Feld, Grund und Beispiel und liefert den normalisierten Wert
 * als Text zurück, so wie er in der Datenbank steht und verglichen wird.
 */
final class Validate
{
    private const MIN_PASSWORD_LENGTH = 12;
    private const MAX_PASSWORD_BYTES = 72;
    private const INT_PATTERN = '/^(0|[1-9][0-9]{0,9})$/';
    private const DECIMAL_PATTERN = '/^\d{1,9}([.,]\d{1,%d})?$/';
    private const EURO_PATTERN = '/^\d{1,7}([.,]\d{1,2})?$/';
    private const SIGNED_EURO_PATTERN = '/^-?\d{1,7}([.,]\d{1,2})?$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';
    private const URL_MAX = 500;
    private const LOCAL_TIMEZONE = 'Europe/Berlin';

    public static function invalid(string $field, string $reason, string $example): DomainError
    {
        return new DomainError('invalid_value', sprintf('Feld „%s“: %s Beispiel: %s', $field, $reason, $example), ['field' => $field]);
    }

    /** Passwortregeln (ADR-006), für Einladung und Rücksetzung; die Meldung zeigt die Oberfläche dem Menschen. */
    public static function password(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new DomainError('invalid_value', 'Das Passwort braucht mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen.', ['field' => 'password']);
        }
        // bcrypt liest nur die ersten 72 Bytes; ein längeres Passwort würde still gekürzt.
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new DomainError('invalid_value', 'Das Passwort darf höchstens ' . self::MAX_PASSWORD_BYTES . ' Bytes lang sein.', ['field' => 'password']);
        }
    }

    public static function date(string $field, mixed $raw, string $example): string
    {
        $value = is_string($raw) ? trim($raw) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw self::invalid($field, 'Das Datum hat die Form YYYY-MM-DD.', $example);
        }

        return $value;
    }

    /** Ein Datum, das ein Mensch im Browser eingibt, liegt nie nach heute (deutsche Zeit); sonst ist es ein Tippfehler. */
    public static function dateNotInFuture(string $field, mixed $raw, string $example, string $reason): string
    {
        $value = self::date($field, $raw, $example);
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
        if ($value > $today) {
            throw self::invalid($field, $reason, $example);
        }

        return $value;
    }

    /** `null` und leerer Text heißen „nicht angegeben“. */
    public static function optionalDate(string $field, mixed $raw, string $example): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::date($field, $raw, $example);
    }

    /** `null` und leerer Text heißen „nicht angegeben“. */
    public static function optionalInt(string $field, mixed $raw, string $example, int $min = 0, ?int $max = null): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::int($field, $raw, $example, $min, $max);
    }

    public static function int(string $field, mixed $raw, string $example, int $min = 0, ?int $max = null): string
    {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if (preg_match(self::INT_PATTERN, $text) !== 1) {
            throw self::invalid($field, 'Erwartet wird eine ganze Zahl ab 0, ohne Einheit und Tausenderpunkt.', $example);
        }
        if ((int) $text < $min || ($max !== null && (int) $text > $max)) {
            $range = $max === null ? sprintf('ab %d', $min) : sprintf('von %d bis %d', $min, $max);

            throw self::invalid($field, sprintf('Erwartet wird eine ganze Zahl %s.', $range), $example);
        }

        return $text;
    }

    public static function text(string $field, mixed $raw, int $min, int $max, string $example): string
    {
        $text = is_string($raw) ? trim($raw) : '';
        $length = mb_check_encoding($text, 'UTF-8') ? mb_strlen($text) : 0;
        $hasControlCharacters = preg_match(self::CONTROL_CHARACTERS, $text) === 1;
        if ($length < $min || $length > $max || $hasControlCharacters) {
            $range = $min === $max ? sprintf('genau %d Zeichen', $min) : sprintf('%d bis %d Zeichen', $min, $max);

            throw self::invalid($field, sprintf('Erwartet wird ein einzeiliger Text mit %s.', $range), $example);
        }

        return $text;
    }

    /** `null` und leerer Text heißen „nicht angegeben“. */
    public static function optionalText(string $field, mixed $raw, int $min, int $max, string $example): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::text($field, $raw, $min, $max, $example);
    }

    /** @param list<string> $allowed */
    public static function enum(string $field, mixed $raw, array $allowed): string
    {
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw self::invalid($field, sprintf('Erlaubt sind %s.', implode(', ', $allowed)), sprintf('{"%s": "%s"}', $field, $allowed[0]));
        }

        return $raw;
    }

    /**
     * Zahl oder Text mit Punkt oder Komma; liefert immer genau `$scale` Nachkommastellen mit Punkt. Negativ nur auf Wunsch
     * (Temperatur); „-0“ wird zu 0, damit der Vergleich mit dem gespeicherten Wert stimmt.
     */
    public static function decimal(string $field, mixed $raw, int $scale, string $example, bool $allowNegative = false): string
    {
        $text = match (true) {
            is_int($raw) => (string) $raw,
            is_float($raw) => number_format($raw, $scale, '.', ''),
            is_string($raw) => trim($raw),
            default => '',
        };
        $isNegative = $allowNegative && str_starts_with($text, '-');
        $unsigned = $isNegative ? substr($text, 1) : $text;
        if (preg_match(sprintf(self::DECIMAL_PATTERN, max($scale, 1)), $unsigned) !== 1 || ($scale === 0 && str_contains($unsigned, '.'))) {
            throw self::invalid($field, sprintf('Erwartet wird eine Zahl %s mit Punkt oder Komma, höchstens %d Nachkommastellen.', $allowNegative ? '(auch negativ)' : 'ab 0', $scale), $example);
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $unsigned), 2), 2, '');
        $normalized = $scale === 0 ? $whole : $whole . '.' . str_pad($fraction, $scale, '0');
        $isZero = trim($normalized, '0.') === '';

        return $isNegative && !$isZero ? '-' . $normalized : $normalized;
    }

    /** Euro als Zahl oder Text mit höchstens zwei Nachkommastellen → Cent als Ganzzahl-Text; negativ nur auf Wunsch (Rabatt). */
    public static function euroToCents(string $field, mixed $raw, bool $allowNegative = false): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Bei verschachtelten Feldern („items[0].net_eur“) zeigt das Beispiel nur den Schlüssel selbst.
        $example = sprintf('{"%s": "1288.70"}', str_contains($field, '.') ? substr($field, (int) strrpos($field, '.') + 1) : $field);
        $text = match (true) {
            is_int($raw) => (string) $raw,
            // Gleitkomma über Text runden, damit 1288.7 nicht als 1288.6999… endet.
            is_float($raw) => number_format($raw, 2, '.', ''),
            is_string($raw) => trim($raw),
            default => '',
        };
        $pattern = $allowNegative ? self::SIGNED_EURO_PATTERN : self::EURO_PATTERN;
        if (preg_match($pattern, $text) !== 1) {
            $reason = $allowNegative
                ? 'Erwartet wird ein Betrag in Euro mit höchstens zwei Nachkommastellen, negativ für einen Rabatt.'
                : 'Erwartet wird ein Betrag ab 0 in Euro mit höchstens zwei Nachkommastellen.';

            throw self::invalid($field, $reason, $example);
        }
        $isNegative = str_starts_with($text, '-');
        [$euros, $cents] = array_pad(explode('.', str_replace(',', '.', ltrim($text, '-')), 2), 2, '');
        $total = (int) $euros * 100 + (int) str_pad($cents, 2, '0');

        return (string) ($isNegative ? -$total : $total);
    }

    /** `true`/`false` → `"1"`/`"0"`, `null` bleibt `null`. */
    public static function bool(string $field, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_bool($raw)) {
            throw self::invalid($field, 'Erwartet wird true oder false.', sprintf('{"%s": true}', $field));
        }

        return $raw ? '1' : '0';
    }

    public static function url(string $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $isUrl = is_string($raw)
            && strlen($raw) <= self::URL_MAX
            && filter_var($raw, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($raw, PHP_URL_SCHEME), ['http', 'https'], true);
        if (!$isUrl) {
            throw new DomainError('invalid_value', sprintf('Feld „%s“: source_url ist eine http(s)-Adresse bis %d Zeichen. Beispiel: "source_url": "https://example.com/seite"', $field, self::URL_MAX), ['field' => $field]);
        }

        return $raw;
    }
}
