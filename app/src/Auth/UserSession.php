<?php

declare(strict_types=1);

namespace Garage\Auth;

use Garage\Db\Transaction;
use Garage\Domain\Actor;
use Garage\Domain\AuditRepository;
use Garage\Domain\DomainError;
use Garage\Domain\TokenRepository;
use Garage\Domain\UserRepository;
use PDO;

/**
 * Aus DISPATCH (ADR-005). Anmeldung eines Nutzers: Access-JWT für 15 Minuten, Refresh-Token als Cookie mit Rotation.
 * Die Rückgaben tragen den Klartext des neuen Refresh-Tokens oder null, wenn kein neuer Cookie gesetzt werden soll.
 */
final class UserSession
{
    public const COOKIE_NAME = 'garage_refresh';
    public const COOKIE_PATH = '/garage/api/auth';
    public const REFRESH_LIFETIME_SECONDS = 2592000;
    private const REFRESH_PREFIX = 'gref_';
    private const GRACE_SECONDS = 30;
    // Hash eines Passworts, das niemand kennt: so kostet auch ein unbekanntes Konto einen vollen password_verify.
    private const DUMMY_PASSWORD_HASH = '$2y$12$Z5I//NHqXJNoUlbSJA/P1u7QHFcxAo.mk8qKBksSFgsIqpmwj7Oea';

    private TokenRepository $tokens;
    private UserRepository $users;
    private AuditRepository $audit;
    private LoginThrottle $throttle;

    public function __construct(private readonly PDO $pdo)
    {
        $this->tokens = new TokenRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->throttle = new LoginThrottle($pdo);
    }

    /**
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string}
     * @throws DomainError `invalid_credentials`, `too_many_attempts`
     */
    public function login(string $email, string $password, string $ip): array
    {
        $this->throttle->prune();
        $this->throttle->assertAllowed($ip);

        $user = $this->users->findByEmail(strtolower(trim($email)));

        // Läuft immer, auch ohne Konto: so verrät weder die Meldung noch die Antwortzeit, ob die E-Mail existiert.
        $isPasswordValid = password_verify($password, $user['password_hash'] ?? self::DUMMY_PASSWORD_HASH);
        if ($user === null || !$isPasswordValid || $user['status'] !== UserRepository::STATUS_ACTIVE) {
            $this->throttle->recordFailure($ip);
            $this->audit->record(Actor::anonymous(), 'auth.login_failed', $user === null ? 'unbekannt' : 'user:' . $user['id']);
            throw new DomainError('invalid_credentials', 'E-Mail oder Passwort stimmt nicht.');
        }

        return $this->start($user);
    }

    /**
     * Neue Sitzung für einen aktiven Nutzer, etwa direkt nach dem Annehmen einer Einladung.
     *
     * @param array{id: int, name: string, email: string} $user
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string}
     */
    public function start(array $user): array
    {
        $refreshToken = Transaction::join($this->pdo, function () use ($user): string {
            $refreshToken = $this->newRefreshToken($user['id'], bin2hex(random_bytes(16)));
            $this->users->touchLogin($user['id']);
            $this->audit->record($this->actorFor($user['id']), 'auth.login', 'user:' . $user['id']);

            return $refreshToken;
        });

        return $this->accessResponse($user) + ['refresh_token' => $refreshToken];
    }

    /**
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string|null}
     * @throws DomainError `session_expired` (unbekannt/abgelaufen/Nutzer gesperrt) oder `session_revoked` (Wiederverwendung)
     */
    public function refresh(?string $refreshToken): array
    {
        $token = $refreshToken === null ? null : $this->tokens->findByHash(hash('sha256', $refreshToken), TokenRepository::KIND_USER_REFRESH);
        if ($token === null || (!$token['revoked'] && !$token['not_expired'])) {
            throw new DomainError('session_expired', 'Die Sitzung ist abgelaufen. Bitte neu anmelden.');
        }

        if ($token['revoked']) {
            return $this->handleRevokedToken($token);
        }

        $user = $this->activeUser($token['user_id']);

        $newToken = Transaction::run($this->pdo, function () use ($token): ?string {
            // Zwei gleichzeitige Refreshs: nur einer gewinnt die Rotation, der andere fällt ins Kulanzfenster.
            if (!$this->tokens->revokeOne($token['id'], self::GRACE_SECONDS)) {
                return null;
            }

            return $this->newRefreshToken($token['user_id'], $token['family']);
        });

        return $this->accessResponse($user) + ['refresh_token' => $newToken];
    }

    public function logout(?string $refreshToken): void
    {
        $token = $refreshToken === null ? null : $this->tokens->findByHash(hash('sha256', $refreshToken), TokenRepository::KIND_USER_REFRESH);
        if ($token === null) {
            return;
        }

        $this->tokens->revokeOne($token['id'], 0);
        $this->audit->record($this->actorFor($token['user_id']), 'auth.logout', 'user:' . $token['user_id']);
    }

    public function logoutAll(int $userId): void
    {
        Transaction::run($this->pdo, function () use ($userId): void {
            $this->tokens->revokeAllUserRefresh($userId);
            $this->audit->record($this->actorFor($userId), 'auth.logout_all', 'user:' . $userId);
        });
    }

    /**
     * @param array{id: int, user_id: int, family: string, revoked: bool, not_expired: bool} $token
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: null}
     */
    private function handleRevokedToken(array $token): array
    {
        if ($token['not_expired']) {
            // Kulanzfenster: der parallele Tab hat den neuen Cookie schon gesetzt.
            return $this->accessResponse($this->activeUser($token['user_id'])) + ['refresh_token' => null];
        }

        Transaction::run($this->pdo, function () use ($token): void {
            $this->tokens->revokeFamily($token['family']);
            $this->audit->record($this->actorFor($token['user_id']), 'auth.reuse', 'user:' . $token['user_id']);
        });
        throw new DomainError('session_revoked', 'Die Sitzung wurde aus Sicherheitsgründen beendet. Bitte neu anmelden.');
    }

    /**
     * @return array{id: int, name: string, email: string}
     * @throws DomainError `session_expired`, wenn der Nutzer fehlt oder gesperrt ist
     */
    private function activeUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== UserRepository::STATUS_ACTIVE) {
            throw new DomainError('session_expired', 'Die Sitzung ist abgelaufen. Bitte neu anmelden.');
        }

        return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    }

    private function newRefreshToken(int $userId, string $family): string
    {
        $plain = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
        $this->tokens->insertUserRefresh($userId, hash('sha256', $plain), $family, self::REFRESH_LIFETIME_SECONDS);

        return $plain;
    }

    private function actorFor(int $userId): Actor
    {
        return Actor::forUser($userId, Actor::VIA_WEB, 'user:' . $userId, Scopes::ALL, $this->pdo);
    }

    /**
     * @param array{id: int, name: string, email: string} $user
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}}
     */
    private function accessResponse(array $user): array
    {
        return [
            'access_token' => Jwt::encode(Jwt::newAccessClaims($user['id'])),
            'expires_in' => Jwt::LIFETIME_SECONDS,
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ];
    }
}
