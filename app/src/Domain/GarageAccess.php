<?php

declare(strict_types=1);

namespace Garage\Domain;

/** Zugriff auf die Verwaltung einer Garage: fremd oder unbekannt ist `not_found` (ADR-008), ohne Inhaber-Rolle `forbidden`. */
final class GarageAccess
{
    public static function assertMember(int $garageId, Actor $actor): void
    {
        if (!$actor->canRead($garageId)) {
            throw new DomainError('not_found', 'Diese Garage gibt es bei dir nicht.', ['garage_id' => $garageId]);
        }
    }

    public static function assertOwner(int $garageId, Actor $actor): void
    {
        self::assertMember($garageId, $actor);
        if (!$actor->isOwner($garageId)) {
            throw self::ownerRequired($garageId);
        }
    }

    /**
     * Sperrt die Inhaber-Zeilen bis zum Ende der Transaktion und prüft den Akteur erneut: seine Rollen im `Actor` stammen
     * von vor der Sperre, inzwischen kann ein anderer Inhaber ihn herabgestuft haben.
     *
     * @return list<int> Nutzer-IDs der Inhaber
     */
    public static function lockOwnersAsOwner(GarageRepository $garages, int $garageId, Actor $actor): array
    {
        $ownerIds = $garages->ownerIdsForUpdate($garageId);
        if (!in_array($actor->userId, $ownerIds, true)) {
            throw self::ownerRequired($garageId);
        }

        return $ownerIds;
    }

    private static function ownerRequired(int $garageId): DomainError
    {
        return new DomainError('forbidden', 'Das darf nur ein Inhaber der Garage.', ['garage_id' => $garageId]);
    }
}
