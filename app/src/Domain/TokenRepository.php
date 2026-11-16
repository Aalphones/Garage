<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Aus DISPATCH (ADR-005): Sitzungen im Browser, feste Tokens und OAuth-Tokens. Jedes Token gehört einem Nutzer. */
final class TokenRepository
{
    public const KIND_USER_REFRESH = 'user_refresh';
    public const KIND_OAUTH_ACCESS = 'oauth_access';
    public const KIND_OAUTH_REFRESH = 'oauth_refresh';
    private const KIND_MCP = 'mcp';

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

    /** @param array{user_id: int, token_hash: string, name: string, scope: string, expires_days: int|null} $fields */
    public function insertMcp(array $fields): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tokens (user_id, token_hash, kind, name, scope, expires_at, revoked, created_at)
             VALUES (?, ?, ?, ?, ?, IF(? IS NULL, NULL, UTC_TIMESTAMP() + INTERVAL ? DAY), 0, UTC_TIMESTAMP())'
        );
        $statement->execute([
            $fields['user_id'],
            $fields['token_hash'],
            self::KIND_MCP,
            $fields['name'],
            $fields['scope'],
            $fields['expires_days'],
            $fields['expires_days'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{id: int, user_id: int, name: string, scope: string}|null gültiges festes Token samt Besitzer */
    public function findActiveMcpByHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, scope FROM tokens
             WHERE token_hash = ? AND kind = ? AND revoked = 0 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())'
        );
        $statement->execute([$hash, self::KIND_MCP]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'name' => (string) $row['name'], 'scope' => (string) $row['scope']];
    }

    public function activeMcpNameExists(int $userId, string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM tokens WHERE user_id = ? AND kind = ? AND revoked = 0 AND name = ? LIMIT 1');
        $statement->execute([$userId, self::KIND_MCP, $name]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> nicht widerrufene feste Tokens dieses Nutzers, neueste zuerst, ohne Hash */
    public function listMcp(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, scope, expires_at, last_used_at, created_at FROM tokens
             WHERE user_id = ? AND kind = ? AND revoked = 0 ORDER BY id DESC'
        );
        $statement->execute([$userId, self::KIND_MCP]);

        return $statement->fetchAll();
    }

    /** Widerruft nur ein eigenes, noch nicht widerrufenes festes Token; false bei fremdem, unbekanntem oder schon widerrufenem. */
    public function revokeMcp(int $id, int $userId): bool
    {
        $statement = $this->pdo->prepare('UPDATE tokens SET revoked = 1 WHERE id = ? AND user_id = ? AND kind = ? AND revoked = 0');
        $statement->execute([$id, $userId, self::KIND_MCP]);

        return $statement->rowCount() === 1;
    }

    public function insertOAuth(string $kind, string $hash, int $userId, string $name, string $clientId, string $scope, string $family, int $lifetimeSeconds): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tokens (user_id, token_hash, kind, name, scope, client_id, family, expires_at, revoked, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, 0, UTC_TIMESTAMP())'
        );
        $statement->execute([$userId, $hash, $kind, $name, $scope, $clientId, $family, $lifetimeSeconds]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ohne Gültigkeitsfilter: der Refresh unterscheidet gültig, Kulanzfenster und Wiederverwendung selbst.
     *
     * @return array{id: int, user_id: int, family: string, client_id: string, name: string, scope: string, revoked: bool, not_expired: bool}|null
     */
    public function findOAuthByHash(string $hash, string $kind): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, family, client_id, name, scope, revoked, (expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()) AS not_expired
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
            'client_id' => (string) $row['client_id'],
            'name' => (string) $row['name'],
            'scope' => (string) $row['scope'],
            'revoked' => (int) $row['revoked'] === 1,
            'not_expired' => (int) $row['not_expired'] === 1,
        ];
    }

    /** @return array{id: int, user_id: int, name: string, scope: string}|null gültiges Access-Token eines nicht widerrufenen Clients und eines aktiven Nutzers */
    public function findActiveOAuthAccess(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.user_id, t.name, t.scope FROM tokens t
             JOIN oauth_clients c ON c.client_id = t.client_id
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.kind = ? AND t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP()
               AND c.revoked = 0 AND u.status = ?'
        );
        $statement->execute([$hash, self::KIND_OAUTH_ACCESS, UserRepository::STATUS_ACTIVE]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'name' => (string) $row['name'], 'scope' => (string) $row['scope']];
    }

    /** Alle Refresh-Tokens der Familie außer `$keepId` sofort widerrufen (es bleibt genau ein gültiger Zweig). */
    public function revokeOtherRefresh(string $family, int $keepId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tokens SET revoked = 1, expires_at = UTC_TIMESTAMP() WHERE family = ? AND kind = ? AND revoked = 0 AND id <> ?'
        );
        $statement->execute([$family, self::KIND_OAUTH_REFRESH, $keepId]);
    }

    /**
     * Je Familie dieses Nutzers mit mindestens einem gültigen Access- oder Refresh-Token; `scope` vom jüngsten Token.
     *
     * @return list<array<string, mixed>> neueste zuerst
     */
    public function listGrants(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.family, MAX(t.client_id) AS client_id, MAX(t.name) AS name,
                    MIN(t.created_at) AS connected_at, MAX(t.last_used_at) AS last_used_at,
                    (SELECT latest.scope FROM tokens latest WHERE latest.family = t.family AND latest.kind IN (?, ?) ORDER BY latest.id DESC LIMIT 1) AS scope
             FROM tokens t
             WHERE t.user_id = ? AND t.kind IN (?, ?)
             GROUP BY t.family
             HAVING SUM(t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP()) > 0
             ORDER BY MAX(t.id) DESC'
        );
        $statement->execute([self::KIND_OAUTH_ACCESS, self::KIND_OAUTH_REFRESH, $userId, self::KIND_OAUTH_ACCESS, self::KIND_OAUTH_REFRESH]);

        return $statement->fetchAll();
    }

    /** @return array{client_id: string, name: string}|null irgendein OAuth-Token der Familie, nur wenn sie diesem Nutzer gehört */
    public function findOAuthFamily(string $family, int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT client_id, name FROM tokens WHERE family = ? AND user_id = ? AND kind IN (?, ?) LIMIT 1');
        $statement->execute([$family, $userId, self::KIND_OAUTH_ACCESS, self::KIND_OAUTH_REFRESH]);
        $row = $statement->fetch();

        return $row === false ? null : ['client_id' => (string) $row['client_id'], 'name' => (string) $row['name']];
    }

    public function touch(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?');
        $statement->execute([$id]);
    }

    public function expiresAt(int $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT expires_at FROM tokens WHERE id = ?');
        $statement->execute([$id]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
