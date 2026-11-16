<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Domain\DomainError;

/** Aus DISPATCH (ADR-005). Zugriffsbereiche eines Tokens. Keiner schließt einen anderen ein. */
final class Scopes
{
    public const READ = 'garage:read';
    public const WRITE = 'garage:write';
    public const FILES = 'garage:files';
    public const ALL = [self::READ, self::WRITE, self::FILES];

    /**
     * Trennt an Leerzeichen und Kommas, entfernt Doppelte und sortiert in der Reihenfolge von ALL.
     *
     * @param string|list<string> $value
     * @return list<string>
     */
    public static function parse(string|array $value): array
    {
        $parts = is_array($value) ? $value : [$value];
        $requested = [];
        foreach ($parts as $part) {
            $pieces = preg_split('/[\s,]+/', (string) $part, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($pieces === false ? [] : $pieces as $scope) {
                $requested[] = $scope;
            }
        }

        $validList = implode(', ', self::ALL);
        if ($requested === []) {
            throw new DomainError('invalid_value', 'Mindestens ein Scope ist nötig. Gültig: ' . $validList . '.', ['field' => 'scopes']);
        }

        $unknown = array_values(array_unique(array_diff($requested, self::ALL)));
        if ($unknown !== []) {
            throw new DomainError(
                'invalid_value',
                sprintf('Unbekannter Scope „%s“. Gültig: %s.', implode(', ', $unknown), $validList),
                ['field' => 'scopes', 'unknown' => $unknown]
            );
        }

        return array_values(array_intersect(self::ALL, $requested));
    }
}
