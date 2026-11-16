<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Session-Teil aus DISPATCH (ADR-005); Phasen 2 und 3 ergänzen feste und OAuth-Tokens. */
final class TokenRepository
{
    public const KIND_USER_REFRESH = 'user_refresh';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insertUserRefresh(int $userId, string $hash, string $family, int $lifetimeSeconds): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO tokens (user_id, token_hash, kind, name, scope, family, expires_at, revoked, created_at)
             VALUES (?, ?, ?, 'sitzung', '', ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, 0, UTC_TIMESTAMP())"
        );
        $statement->execute([$userId, $hash, self::KIND_USER_REFRESH, $family, $lifetimeSeconds]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ohne Gültigkeitsfilter: die Anmeldung unterscheidet gültig, Kulanzfenster und Wiederverwendung selbst.
     *
     * @return array{id: int, user_id: int, family: string, revoked: bool, not_expired: bool}|null
     */
    public function findByHash(string $hash, string $kind): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, family, revoked, (expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()) AS not_expired
             FROM tokens WHERE token_hash = ? AND kind = ?'
        );
        $statement->execute([$hash, $kind]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'family' => (string) $row['family'],
            'revoked' => (int) $row['revoked'] === 1,
            'not_expired' => (int) $row['not_expired'] === 1,
        ];
    }

    /** Widerruft ein noch gültiges Token; `expires_at` wird auf jetzt + `graceSeconds` gesetzt (Kulanzfenster). false, wenn es schon widerrufen war. */
    public function revokeOne(int $id, int $graceSeconds): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE tokens SET revoked = 1, expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE id = ? AND revoked = 0'
        );
        $statement->execute([$graceSeconds, $id]);

        return $statement->rowCount() === 1;
    }

    public function revokeFamily(string $family): void
    {
        $statement = $this->pdo->prepare('UPDATE tokens SET revoked = 1, expires_at = UTC_TIMESTAMP() WHERE family = ?');
        $statement->execute([$family]);
    }

    /** Nur die Sitzungen dieses Nutzers; Sitzungen anderer Nutzer bleiben unberührt. */
    public function revokeAllUserRefresh(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tokens SET revoked = 1, expires_at = UTC_TIMESTAMP() WHERE user_id = ? AND kind = ? AND revoked = 0'
        );
        $statement->execute([$userId, self::KIND_USER_REFRESH]);
    }
}
