<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Domain\DomainError;
use Garage\Util\Env;

/**
 * Signierter Link auf die Druckansicht einer Akte (ADR-018), aufgebaut wie `FileLink`: `base64url(payload) . '.' . base64url(hmac)`.
 * Der Link trägt Fahrzeug und Nutzer; beim Öffnen wird der Zugriff mit den heutigen Rollen dieses Nutzers geprüft.
 */
final class PrintLink
{
    public const TTL_SECONDS = 600;
    public const PATH_PREFIX = '/garage/akte/';
    private const INVALID_MESSAGE = 'Link abgelaufen — in GARAGE erneut auf Drucken tippen.';

    /** @return array{slug: string, expires_at: int} */
    public static function sign(int $vehicleId, int $userId): array
    {
        $expiresAt = time() + self::TTL_SECONDS;
        $payload = json_encode(['v' => $vehicleId, 'u' => $userId, 'e' => $expiresAt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['slug' => self::encode($payload) . '.' . self::encode(self::signature($payload)), 'expires_at' => $expiresAt];
    }

    /** @return array{vehicle_id: int, user_id: int} */
    public static function verify(string $slug): array
    {
        $parts = explode('.', $slug);
        if (count($parts) !== 2) {
            throw self::invalid();
        }
        $payload = self::decode($parts[0]);
        $signature = self::decode($parts[1]);
        if ($payload === null || $signature === null || !hash_equals(self::signature($payload), $signature)) {
            throw self::invalid();
        }

        $claims = json_decode($payload, true);
        $isValid = is_array($claims)
            && is_int($claims['v'] ?? null)
            && is_int($claims['u'] ?? null)
            && is_int($claims['e'] ?? null)
            && $claims['e'] > time();
        if (!$isValid) {
            throw self::invalid();
        }

        return ['vehicle_id' => $claims['v'], 'user_id' => $claims['u']];
    }

    private static function signature(string $payload): string
    {
        $secret = Env::get('GARAGE_JWT_SECRET');
        if (strlen($secret) < Jwt::MIN_SECRET_LENGTH) {
            throw new DomainError('not_configured', 'Der Server-Schlüssel ist nicht eingerichtet.');
        }
        // Eigener Schlüssel je Zweck: ein Druck-Link geht nie als Dateilink oder JWT durch und umgekehrt.
        $key = hash_hmac('sha256', 'garage-print', $secret, true);

        return hash_hmac('sha256', $payload, $key, true);
    }

    private static function invalid(): DomainError
    {
        return new DomainError('not_found', self::INVALID_MESSAGE);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
