<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Domain\DomainError;
use Garage\Util\Env;

/**
 * Signierte Dateilinks (E3, ADR-012): `base64url(payload) . '.' . base64url(hmac)`. Der Link ist die Berechtigung,
 * er braucht keine Anmeldung und läuft nach kurzer Zeit ab.
 */
final class FileLink
{
    public const DEFAULT_TTL_SECONDS = 600;
    private const FILE_PATTERN = '#^(original\.(jpg|pdf)|1568\.jpg|400\.jpg|pages/[0-9]{1,2}\.jpg)$#';
    private const INVALID_MESSAGE = 'Der Link ist ungültig oder abgelaufen.';

    public static function sign(int $documentId, string $file, ?int $ttl = null): string
    {
        $payload = json_encode(
            ['d' => $documentId, 'f' => $file, 'e' => time() + ($ttl ?? self::DEFAULT_TTL_SECONDS)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return self::encode($payload) . '.' . self::encode(self::signature($payload));
    }

    /** @return array{document_id: int, file: string} */
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
            && is_int($claims['d'] ?? null)
            && is_string($claims['f'] ?? null)
            && is_int($claims['e'] ?? null)
            && $claims['e'] > time()
            && preg_match(self::FILE_PATTERN, $claims['f']) === 1;
        if (!$isValid) {
            throw self::invalid();
        }

        return ['document_id' => $claims['d'], 'file' => $claims['f']];
    }

    private static function signature(string $payload): string
    {
        $secret = Env::get('GARAGE_JWT_SECRET');
        if (strlen($secret) < Jwt::MIN_SECRET_LENGTH) {
            throw new DomainError('not_configured', 'Der Server-Schlüssel ist nicht eingerichtet.');
        }
        // Eigener Schlüssel je Zweck, damit eine Signatur nie als JWT-Signatur durchgeht.
        $key = hash_hmac('sha256', 'garage-files', $secret, true);

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
