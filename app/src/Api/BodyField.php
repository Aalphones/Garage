<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Domain\DomainError;

/** Typisierte Felder aus einem JSON-Body; ein falscher Typ ist `invalid_value` mit Feldname. */
final class BodyField
{
    /** @param array<string, mixed> $body */
    public static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new DomainError('invalid_value', $field . ' fehlt oder ist kein Text.', ['field' => $field]);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    public static function optionalString(array $body, string $field): ?string
    {
        if (!isset($body[$field])) {
            return null;
        }

        return self::requiredString($body, $field);
    }

    /** @param array<string, mixed> $body */
    public static function optionalInt(array $body, string $field): ?int
    {
        if (!isset($body[$field])) {
            return null;
        }
        if (!is_int($body[$field])) {
            throw new DomainError('invalid_value', $field . ' muss eine ganze Zahl sein.', ['field' => $field]);
        }

        return $body[$field];
    }
}
