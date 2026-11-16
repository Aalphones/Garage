<?php

declare(strict_types=1);

namespace Garage\OAuth;

use Garage\Auth\Scopes;
use Garage\Db\Transaction;
use Garage\Domain\Actor;
use Garage\Domain\AuditRepository;
use Garage\Domain\DomainError;
use Garage\Domain\TokenRepository;
use Garage\Domain\UserRepository;
use PDO;

/**
 * Gibt OAuth-Tokens aus (Code-Tausch, Refresh mit Rotation), widerruft sie und prüft Access-Tokens am MCP-Endpunkt. Kennt kein HTTP.
 * Jedes Token trägt die `user_id` des Nutzers, der der App zugestimmt hat; sie wandert vom Code in beide Tokens.
 */
final class TokenIssuer
{
    public const ACCESS_PREFIX = 'goa_';
    private const REFRESH_PREFIX = 'gor_';
    private const PLAIN_LENGTH = 68;
    private const OFFLINE_SCOPE = 'offline_access';

    private OAuthRepository $repository;
    private TokenRepository $tokens;
    private UserRepository $users;
    private AuditRepository $audit;
    private ClientRegistry $clients;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
        $this->repository = new OAuthRepository($pdo);
        $this->tokens = new TokenRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->clients = new ClientRegistry($pdo, $config);
    }

    /**
     * @param array<string, mixed> $form
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string, refresh_token: string}
     * @throws OAuthError
     */
    public function exchangeCode(array $form): array
    {
        $code = $this->required($form, 'code');
        $redirectUri = $this->required($form, 'redirect_uri');
        $verifier = $this->required($form, 'code_verifier');
        $client = $this->authenticateClient($form);

        $row = $this->repository->findByCodeHash(hash('sha256', $code));
        if ($row === null) {
            throw $this->invalidGrant('Der Code ist unbekannt.');
        }
        if ($row['status'] === OAuthRepository::STATUS_USED) {
            $this->revokeReusedCode($row, $client['name']);
            throw $this->invalidGrant('Der Code wurde schon eingelöst.');
        }
        if ($row['status'] !== OAuthRepository::STATUS_APPROVED || (int) $row['not_expired'] !== 1 || $row['user_id'] === null) {
            throw $this->invalidGrant('Der Code ist abgelaufen.');
        }

        $isMatching = $row['client_id'] === $client['client_id'] && $row['redirect_uri'] === $redirectUri;
        if (!$isMatching || !hash_equals((string) $row['challenge'], self::challengeFor($verifier))) {
            throw $this->invalidGrant('Client, redirect_uri oder code_verifier passen nicht zum Code.');
        }
        $this->assertResource($form['resource'] ?? null);

        $userId = (int) $row['user_id'];
        $this->assertUserActive($userId);

        return Transaction::run($this->pdo, function () use ($row, $client, $userId): array {
            if (!$this->repository->transition((int) $row['id'], OAuthRepository::STATUS_APPROVED, OAuthRepository::STATUS_USED)) {
                throw $this->invalidGrant('Der Code wurde schon eingelöst.');
            }

            // `offline_access` wird angenommen, steuert aber nichts: jede App bekommt ein Refresh-Token (wie DISPATCH ADR-027).
            $garageScopes = array_values(array_diff(explode(' ', (string) $row['scope']), [self::OFFLINE_SCOPE]));
            $issued = $this->issuePair($client, $userId, implode(' ', $garageScopes), (string) $row['family']);
            $this->audit->record($this->actorFor($userId, $client['name'], $garageScopes), 'oauth.token', 'client:' . $client['client_id'], null, null, ['scopes' => $garageScopes]);
            $this->repository->touchClient($client['client_id']);

            return $issued['response'];
        });
    }

    /**
     * Rotation wie bei der Anmeldung im Browser, angepasst auf OAuth.
     *
     * @param array<string, mixed> $form
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string, refresh_token: string}
     * @throws OAuthError
     */
    public function refresh(array $form): array
    {
        $plain = $this->required($form, 'refresh_token');
        $client = $this->authenticateClient($form);

        $token = strlen($plain) === self::PLAIN_LENGTH && str_starts_with($plain, self::REFRESH_PREFIX)
            ? $this->tokens->findOAuthByHash(hash('sha256', $plain), TokenRepository::KIND_OAUTH_REFRESH)
            : null;
        if ($token === null || $token['client_id'] !== $client['client_id']) {
            throw $this->invalidGrant('Das Refresh-Token ist unbekannt.');
        }
        if ($token['revoked'] && !$token['not_expired']) {
            $this->revokeReusedRefresh($token);
            throw $this->invalidGrant('Das Refresh-Token wurde schon verwendet. Die Verbindung wurde beendet; bitte neu verbinden.');
        }
        if (!$token['revoked'] && !$token['not_expired']) {
            throw $this->invalidGrant('Das Refresh-Token ist abgelaufen.');
        }
        $this->assertUserActive($token['user_id']);

        $scope = $this->narrowedScope($form['scope'] ?? null, $token['scope']);

        return Transaction::run($this->pdo, function () use ($token, $client, $scope): array {
            // Zwei gleichzeitige Refreshs: nur einer gewinnt die Rotation, der andere fällt ins Kulanzfenster.
            $isRotation = !$token['revoked'] && $this->tokens->revokeOne($token['id'], (int) $this->config['oauth']['grace_seconds']);
            $issued = $this->issuePair($client, $token['user_id'], $scope, $token['family']);
            if (!$isRotation) {
                // Kulanzfenster: das Token des ersten Refreshs wird ungültig, es bleibt genau ein Zweig.
                $this->tokens->revokeOtherRefresh($token['family'], (int) $issued['refresh_id']);
            }
            $this->repository->touchClient($client['client_id']);

            return $issued['response'];
        });
    }

    /**
     * RFC 7009: Token (Access oder Refresh) samt Familie widerrufen, wenn es zum Client gehört; sonst stillschweigend nichts.
     * Öffentliche Clients haben kein Geheimnis — der Besitz des Tokens ist der Nachweis.
     */
    public function revoke(string $plain, ?string $clientId): void
    {
        $kind = match (true) {
            str_starts_with($plain, self::ACCESS_PREFIX) => TokenRepository::KIND_OAUTH_ACCESS,
            str_starts_with($plain, self::REFRESH_PREFIX) => TokenRepository::KIND_OAUTH_REFRESH,
            default => null,
        };
        $token = $kind === null ? null : $this->tokens->findOAuthByHash(hash('sha256', $plain), $kind);
        if ($token === null || ($clientId !== null && $clientId !== $token['client_id'])) {
            return;
        }

        Transaction::run($this->pdo, function () use ($token): void {
            $this->tokens->revokeFamily($token['family']);
            $this->audit->record($this->actorFor($token['user_id'], $token['name'], []), 'oauth.revoke', 'client:' . $token['client_id']);
        });
    }

    /** @return array{id: int, user_id: int, name: string, scopes: list<string>}|null */
    public function authenticate(string $plain): ?array
    {
        if (strlen($plain) !== self::PLAIN_LENGTH || !str_starts_with($plain, self::ACCESS_PREFIX)) {
            return null;
        }

        $row = $this->tokens->findActiveOAuthAccess(hash('sha256', $plain));
        if ($row === null) {
            return null;
        }
        $this->tokens->touch($row['id']);

        return ['id' => $row['id'], 'user_id' => $row['user_id'], 'name' => $row['name'], 'scopes' => explode(' ', $row['scope'])];
    }

    /** @return list<array{family: string, client_name: string, client_host: string, scopes: list<string>, connected_at: string, last_used_at: string|null}> */
    public function grants(Actor $actor): array
    {
        $grants = [];
        foreach ($this->tokens->listGrants((int) $actor->userId) as $row) {
            $grants[] = [
                'family' => (string) $row['family'],
                'client_name' => (string) $row['name'],
                'client_host' => ClientRegistry::hostFor((string) $row['client_id']),
                'scopes' => explode(' ', (string) $row['scope']),
                'connected_at' => (string) $row['connected_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            ];
        }

        return $grants;
    }

    /** Fremde und unbekannte Familien sind beide `not_found`, damit sich fremde Verbindungen nicht erraten lassen. */
    public function disconnect(string $family, Actor $actor): void
    {
        $known = preg_match('/^[0-9a-f]{32}$/', $family) === 1 ? $this->tokens->findOAuthFamily($family, (int) $actor->userId) : null;
        if ($known === null) {
            throw new DomainError('not_found', 'Diese verbundene App gibt es nicht.');
        }

        Transaction::run($this->pdo, function () use ($family, $known, $actor): void {
            $this->tokens->revokeFamily($family);
            $this->audit->record($actor, 'oauth.disconnect', 'client:' . $known['client_id']);
        });
    }

    /** `BASE64URL(SHA256(verifier))` (RFC 7636, S256). */
    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Alle Clients sind öffentlich (CIMD, PKCE statt Geheimnis). Kein Netzabruf: der Client wurde bei /authorize aufgelöst.
     *
     * @param array<string, mixed> $form
     * @return array{client_id: string, kind: string, name: string, host: string, redirect_uris: list<string>}
     * @throws OAuthError `invalid_client`
     */
    private function authenticateClient(array $form): array
    {
        try {
            return $this->clients->stored($this->required($form, 'client_id'));
        } catch (OAuthError) {
            throw new OAuthError('invalid_client', 'Der Client ist unbekannt oder widerrufen.', 401);
        }
    }

    /**
     * @param array{client_id: string, name: string} $client
     * @return array{response: array{access_token: string, token_type: string, expires_in: int, scope: string, refresh_token: string}, refresh_id: int}
     */
    private function issuePair(array $client, int $userId, string $scope, string $family): array
    {
        $accessSeconds = (int) $this->config['oauth']['access_seconds'];
        $access = self::ACCESS_PREFIX . bin2hex(random_bytes(32));
        $this->tokens->insertOAuth(TokenRepository::KIND_OAUTH_ACCESS, hash('sha256', $access), $userId, $client['name'], $client['client_id'], $scope, $family, $accessSeconds);

        $refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
        $refreshId = $this->tokens->insertOAuth(
            TokenRepository::KIND_OAUTH_REFRESH,
            hash('sha256', $refresh),
            $userId,
            $client['name'],
            $client['client_id'],
            $scope,
            $family,
            (int) $this->config['oauth']['refresh_seconds']
        );

        $response = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $accessSeconds, 'scope' => $scope, 'refresh_token' => $refresh];

        return ['response' => $response, 'refresh_id' => $refreshId];
    }

    /** @throws OAuthError `invalid_scope` */
    private function narrowedScope(mixed $requested, string $current): string
    {
        if (!is_string($requested) || trim($requested) === '') {
            return $current;
        }

        $wanted = array_values(array_unique(preg_split('/\s+/', trim($requested)) ?: []));
        $allowed = explode(' ', $current);
        if (array_diff($wanted, $allowed) !== []) {
            throw new OAuthError('invalid_scope', 'Der Scope geht über den bisher erteilten hinaus.');
        }

        return implode(' ', array_values(array_intersect(Scopes::ALL, $wanted)));
    }

    /** @throws OAuthError `invalid_target` */
    private function assertResource(mixed $resource): void
    {
        if ($resource === null || $resource === '') {
            return;
        }
        if ($resource !== Discovery::resource($this->config)) {
            throw new OAuthError('invalid_target', 'Die Ressource ist nicht dieser MCP-Endpunkt.');
        }
    }

    /** Ein gesperrter Nutzer bekommt keine neuen Tokens, auch nicht per Code oder Refresh. */
    private function assertUserActive(int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== UserRepository::STATUS_ACTIVE) {
            throw $this->invalidGrant('Das Konto ist nicht aktiv.');
        }
    }

    /**
     * @param array<string, mixed> $form
     * @throws OAuthError `invalid_request`
     */
    private function required(array $form, string $name): string
    {
        $value = $form[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new OAuthError('invalid_request', sprintf('Der Parameter %s fehlt.', $name));
        }

        return $value;
    }

    /** @param list<string> $scopes */
    private function actorFor(int $userId, string $clientName, array $scopes): Actor
    {
        return Actor::forUser($userId, Actor::VIA_OAUTH, 'oauth:' . $clientName, $scopes, $this->pdo);
    }

    /** @param array<string, mixed> $row */
    private function revokeReusedCode(array $row, string $clientName): void
    {
        if ($row['family'] === null || $row['user_id'] === null) {
            return;
        }

        Transaction::run($this->pdo, function () use ($row, $clientName): void {
            $this->tokens->revokeFamily((string) $row['family']);
            $this->audit->record(
                $this->actorFor((int) $row['user_id'], $clientName, []),
                'oauth.reuse',
                'client:' . $row['client_id'],
                null,
                null,
                ['via' => 'code']
            );
        });
    }

    /** @param array{user_id: int, family: string, client_id: string, name: string} $token */
    private function revokeReusedRefresh(array $token): void
    {
        Transaction::run($this->pdo, function () use ($token): void {
            $this->tokens->revokeFamily($token['family']);
            $this->audit->record(
                $this->actorFor($token['user_id'], $token['name'], []),
                'oauth.reuse',
                'client:' . $token['client_id'],
                null,
                null,
                ['via' => 'refresh']
            );
        });
    }

    private function invalidGrant(string $description): OAuthError
    {
        return new OAuthError('invalid_grant', $description);
    }
}
