<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Domain\DomainError;
use Garage\Domain\GarageRepository;
use Garage\Util\Env;

/**
 * Geheimer Abo-Link je Garage (ADR-024): `/garage/kalender/<garage_id>-<sig>.ics`. Die Signatur deckt die Version
 * `garages.calendar_version`; Erneuern zählt sie hoch und macht jeden alten Link ungültig.
 */
final class CalendarLink
{
    public const PATH_PREFIX = '/garage/kalender/';
    public const SUFFIX = '.ics';
    private const SIGNATURE_LENGTH = 32;

    public static function url(int $garageId, int $version): string
    {
        return Env::get('GARAGE_BASE_URL') . self::PATH_PREFIX . $garageId . '-' . self::signature($garageId, $version) . self::SUFFIX;
    }

    /** Garage-ID bei gültigem Slug (ohne `.ics`), sonst `null` — auch für einen Link mit alter Version. */
    public static function verify(string $slug, GarageRepository $garages): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,9})-([A-Za-z0-9_-]{' . self::SIGNATURE_LENGTH . '})$/', $slug, $matches) !== 1) {
            return null;
        }
        $garageId = (int) $matches[1];
        $version = $garages->calendarVersion($garageId);
        if ($version === null) {
            return null;
        }

        return hash_equals(self::signature($garageId, $version), $matches[2]) ? $garageId : null;
    }

    private static function signature(int $garageId, int $version): string
    {
        $secret = Env::get('GARAGE_JWT_SECRET');
        if (strlen($secret) < Jwt::MIN_SECRET_LENGTH) {
            throw new DomainError('not_configured', 'Der Server-Schlüssel ist nicht eingerichtet.');
        }
        // Eigener Schlüssel je Zweck: ein Kalender-Link geht nie als Druck- oder Dateilink durch und umgekehrt.
        $key = hash_hmac('sha256', 'garage-calendar', $secret, true);
        $mac = hash_hmac('sha256', sprintf('calendar|%d|%d', $garageId, $version), $key, true);

        return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, self::SIGNATURE_LENGTH);
    }
}
