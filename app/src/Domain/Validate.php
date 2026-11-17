<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;

/**
 * Prüfer für Eingabewerte. Jeder wirft `invalid_value` mit Feld, Grund und Beispiel und liefert den normalisierten Wert
 * als Text zurück, so wie er in der Datenbank steht und verglichen wird.
 */
final class Validate
{
    private const INT_PATTERN = '/^(0|[1-9][0-9]{0,9})$/';
    private const DECIMAL_PATTERN = '/^\d{1,9}([.,]\d{1,%d})?$/';
    private const EURO_PATTERN = '/^\d{1,7}([.,]\d{1,2})?$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';
    private const URL_MAX = 500;

    public static function invalid(string $field, string $reason, string $example): DomainError
    {
        return new DomainError('invalid_value', sprintf('Feld „%s“: %s Beispiel: %s', $field, $reason, $example), ['field' => $field]);
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

    /** Zahl oder Text mit Punkt oder Komma; liefert immer genau `$scale` Nachkommastellen mit Punkt. */
    public static function decimal(string $field, mixed $raw, int $scale, string $example): string
    {
        $text = match (true) {
            is_int($raw) => (string) $raw,
            is_float($raw) => number_format($raw, $scale, '.', ''),
            is_string($raw) => trim($raw),
            default => '',
        };
        if (preg_match(sprintf(self::DECIMAL_PATTERN, max($scale, 1)), $text) !== 1 || ($scale === 0 && str_contains($text, '.'))) {
            throw self::invalid($field, sprintf('Erwartet wird eine Zahl ab 0 mit Punkt oder Komma, höchstens %d Nachkommastellen.', $scale), $example);
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $text), 2), 2, '');

        return $scale === 0 ? $whole : $whole . '.' . str_pad($fraction, $scale, '0');
    }

    /** Euro als Zahl oder Text mit höchstens zwei Nachkommastellen → Cent als Ganzzahl-Text. */
    public static function euroToCents(string $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $example = sprintf('{"%s": "1288.70"}', $field);
        $text = match (true) {
            is_int($raw) => (string) $raw,
            // Gleitkomma über Text runden, damit 1288.7 nicht als 1288.6999… endet.
            is_float($raw) => number_format($raw, 2, '.', ''),
            is_string($raw) => trim($raw),
            default => '',
        };
        if (preg_match(self::EURO_PATTERN, $text) !== 1) {
            throw self::invalid($field, 'Erwartet wird ein Betrag ab 0 in Euro mit höchstens zwei Nachkommastellen.', $example);
        }
        [$euros, $cents] = array_pad(explode('.', str_replace(',', '.', $text), 2), 2, '');

        return (string) ((int) $euros * 100 + (int) str_pad($cents, 2, '0'));
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
