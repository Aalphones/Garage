<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use Garage\Db\Transaction;
use PDO;

/** Feste Tokens: Klartext `gmcp_` + 64 Hex, gespeichert wird nur der SHA-256-Hash (aus DISPATCH, ADR-005). Jedes Token gehört einem Nutzer. */
final class TokenService
{
    private const PREFIX = 'gmcp_';
    private const PLAIN_LENGTH = 69;
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
    private const MAX_DAYS = 3650;

    private TokenRepository $tokens;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->tokens = new TokenRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * @param list<string>|string $scopes
     * @return array{token: string, id: int, name: string, scopes: list<string>, expires_at: string|null}
     */
    public function create(string $name, array|string $scopes, ?int $expiresDays, Actor $actor): array
    {
        $userId = (int) $actor->userId;
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainError(
                'invalid_value',
                'Der Name darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten, muss mit Buchstabe oder Ziffer beginnen und höchstens 40 Zeichen lang sein (z. B. claude-code).',
                ['field' => 'name']
            );
        }
        $scopeList = Scopes::parse($scopes);
        if ($expiresDays !== null && ($expiresDays < 1 || $expiresDays > self::MAX_DAYS)) {
            throw new DomainError('invalid_value', sprintf('Die Laufzeit muss zwischen 1 und %d Tagen liegen (oder ohne Ablauf).', self::MAX_DAYS), ['field' => 'expires_days']);
        }

        return Transaction::join($this->pdo, function () use ($userId, $name, $scopeList, $expiresDays, $actor): array {
            if ($this->tokens->activeMcpNameExists($userId, $name)) {
                throw new DomainError('name_taken', sprintf('Du hast schon ein aktives Token „%s“. Anderen Namen wählen oder das alte widerrufen.', $name), ['field' => 'name']);
            }

            $plain = self::PREFIX . bin2hex(random_bytes(32));
            $id = $this->tokens->insertMcp([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $plain),
                'name' => $name,
                'scope' => implode(' ', $scopeList),
                'expires_days' => $expiresDays,
            ]);
            $expiresAt = $this->tokens->expiresAt($id);
            // Der Klartext steht nie im Audit-Log.
            $this->audit->record($actor, 'token.create', 'token:' . $id, null, null, [
                'name' => $name,
                'scopes' => $scopeList,
                'expires_at' => $expiresAt,
            ]);

            return ['token' => $plain, 'id' => $id, 'name' => $name, 'scopes' => $scopeList, 'expires_at' => $expiresAt];
        });
    }

    /** @return list<array{id: int, name: string, scopes: list<string>, expires_at: string|null, last_used_at: string|null, created_at: string}> */
    public function list(Actor $actor): array
    {
        $entries = [];
        foreach ($this->tokens->listMcp((int) $actor->userId) as $row) {
            $entries[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'scopes' => explode(' ', (string) $row['scope']),
                'expires_at' => $row['expires_at'],
                'last_used_at' => $row['last_used_at'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $entries;
    }

    /** Fremde und unbekannte IDs sind beide `not_found`, damit sich fremde IDs nicht erraten lassen. */
    public function revoke(int $id, Actor $actor): void
    {
        Transaction::join($this->pdo, function () use ($id, $actor): void {
            if (!$this->tokens->revokeMcp($id, (int) $actor->userId)) {
                throw new DomainError('not_found', 'Ein aktives Token mit dieser ID gibt es bei dir nicht.', ['token_id' => $id]);
            }
            $this->audit->record($actor, 'token.revoke', 'token:' . $id);
        });
    }

    /** @return array{id: int, user_id: int, name: string, scopes: list<string>}|null */
    public function authenticate(string $plain): ?array
    {
        if (strlen($plain) !== self::PLAIN_LENGTH || !str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $row = $this->tokens->findActiveMcpByHash(hash('sha256', $plain));
        if ($row === null) {
            return null;
        }
        $this->tokens->touch($row['id']);

        return [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'scopes' => explode(' ', $row['scope']),
        ];
    }
}
