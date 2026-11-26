<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\LoginThrottle;
use Garage\Auth\Scopes;
use Garage\Auth\UserSession;
use Garage\Db\Transaction;
use Garage\Util\Env;
use PDO;

/**
 * Passwort-Rücksetzung ohne E-Mail (ADR-026): ein Inhaber erzeugt den Link und gibt ihn selbst weiter. Erlaubt ist das nur,
 * wenn er jede Garage des Ziel-Kontos als Inhaber verwaltet — sonst übernähme er ein Konto, das auch eine fremde sieht.
 * Die Regel gilt beim Erzeugen und erneut beim Ansehen und Einlösen, weil sich Rollen inzwischen ändern können.
 */
final class PasswordResetService
{
    private const LIFETIME_HOURS = 24;
    private const TOKEN_PREFIX = 'gpwr_';
    private const LINK_ERROR_CODES = ['reset_not_found', 'reset_used', 'reset_expired'];

    private PasswordResetRepository $resets;
    private UserRepository $users;
    private GarageRepository $garages;
    private AuditRepository $audit;
    private LoginThrottle $throttle;

    public function __construct(private readonly PDO $pdo)
    {
        $this->resets = new PasswordResetRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->garages = new GarageRepository($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->throttle = new LoginThrottle($pdo);
    }

    /**
     * Ein älterer offener Link desselben Kontos wird dabei ungültig.
     *
     * @return array{link: string, expires_at: string}
     * @throws DomainError `not_found`, `forbidden`, `invalid_value`
     */
    public function create(int $garageId, int $userId, Actor $actor): array
    {
        GarageAccess::assertOwner($garageId, $actor);

        return Transaction::run($this->pdo, function () use ($garageId, $userId, $actor): array {
            // Die Sperre muss der erste Lesezugriff bleiben: zwei gleichzeitige Aufrufe ließen sonst zwei Links offen.
            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null || $this->garages->member($garageId, $userId) === null) {
                throw new DomainError('not_found', 'Dieses Mitglied gibt es in der Garage nicht.', ['user_id' => $userId]);
            }
            if (!$this->managesAllGaragesOf((int) $actor->userId, $userId)) {
                throw new DomainError('forbidden', 'Einen Rücksetz-Link kann nur erzeugen, wer jede Garage dieses Kontos als Inhaber verwaltet.', ['user_id' => $userId]);
            }
            if ($user['status'] !== UserRepository::STATUS_ACTIVE) {
                throw new DomainError('invalid_value', 'Dieses Konto ist gesperrt und bekommt keinen Rücksetz-Link.', ['field' => 'user_id']);
            }

            $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
            $this->resets->invalidateOpen($userId);
            $resetId = $this->resets->insert(hash('sha256', $token), $userId, (int) $actor->userId, self::LIFETIME_HOURS);
            $this->audit->record($actor, 'password.reset_create', 'user:' . $userId, $garageId, null, ['reset_id' => $resetId]);

            return [
                'link' => Env::get('GARAGE_BASE_URL') . '/garage/passwort.html#' . $token,
                'expires_at' => $this->resets->expiresAt($resetId),
            ];
        });
    }

    /**
     * Nennt nur Name und Ablauf, nie die E-Mail.
     *
     * @return array{name: string, expires_at: string}
     * @throws DomainError `reset_not_found`, `reset_used`, `reset_expired`, `too_many_attempts`
     */
    public function preview(string $token, string $ip): array
    {
        return $this->throttled($ip, function () use ($token): array {
            $reset = $this->resets->findByHash(hash('sha256', $token), false);
            $this->assertExists($reset);
            $user = $this->requireRedeemable($reset);

            return ['name' => $user['name'], 'expires_at' => $reset['expires_at']];
        });
    }

    /**
     * Setzt das neue Passwort, beendet alle Browser-Sitzungen des Kontos und startet eine neue. Feste Zugänge und
     * verbundene Apps bleiben (ADR-026).
     *
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string}
     * @throws DomainError `invalid_value`, `reset_not_found`, `reset_used`, `reset_expired`, `too_many_attempts`
     */
    public function accept(string $token, string $password, string $ip): array
    {
        return $this->throttled($ip, function () use ($token, $password): array {
            Validate::password($password);

            $tokenHash = hash('sha256', $token);
            $found = $this->resets->findByHash($tokenHash, false);
            $this->assertExists($found);

            // Der Hash läuft vor der Transaktion: bcrypt dauert, und die Sperren sollen kurz sein.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            return Transaction::run($this->pdo, fn (): array => $this->redeem($tokenHash, $found['user_id'], $passwordHash));
        });
    }

    /** @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string} */
    private function redeem(string $tokenHash, int $userId, string $passwordHash): array
    {
        // Erst das Konto, dann den Link sperren — dieselbe Reihenfolge wie in `create`, sonst droht ein Deadlock.
        $this->users->findByIdForUpdate($userId);
        $reset = $this->resets->findByHash($tokenHash, true);
        $this->assertExists($reset);
        $user = $this->requireRedeemable($reset);

        $this->users->updatePassword($userId, $passwordHash);
        $this->resets->markUsed($reset['id']);

        $actor = Actor::forUser($userId, Actor::VIA_WEB, 'user:' . $userId, Scopes::ALL, $this->pdo);
        $this->audit->record($actor, 'password.reset_accept', 'user:' . $userId, null, null, ['reset_id' => $reset['id'], 'created_by' => $reset['created_by']]);

        // Reihenfolge: erst alle alten Sitzungen beenden, dann die neue starten.
        $session = new UserSession($this->pdo);
        $session->logoutAll($userId);

        return $session->start(['id' => $userId, 'name' => $user['name'], 'email' => $user['email']]);
    }

    /**
     * Login-Bremse je IP, damit niemand Tokens durchprobiert. Gezählt wird außerhalb jeder Transaktion, sonst nähme das
     * Zurückrollen den Fehlversuch mit.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function throttled(string $ip, callable $work): mixed
    {
        $this->throttle->prune();
        $this->throttle->assertAllowed($ip);

        try {
            return $work();
        } catch (DomainError $error) {
            if (in_array($error->errorCode, self::LINK_ERROR_CODES, true)) {
                $this->throttle->recordFailure($ip);
            }

            throw $error;
        }
    }

    /**
     * @param array{id: int, user_id: int, created_by: int, expires_at: string, is_expired: bool, is_used: bool}|null $reset
     * @phpstan-assert !null $reset
     */
    private function assertExists(?array $reset): void
    {
        if ($reset === null) {
            throw new DomainError('reset_not_found', 'Diesen Rücksetz-Link gibt es nicht. Bitte den Link prüfen oder einen neuen anfordern.');
        }
    }

    /**
     * @param array{id: int, user_id: int, created_by: int, expires_at: string, is_expired: bool, is_used: bool} $reset
     * @return array{id: int, email: string, name: string, password_hash: string, status: string} das Ziel-Konto
     * @throws DomainError `reset_used`, `reset_expired`
     */
    private function requireRedeemable(array $reset): array
    {
        if ($reset['is_used']) {
            throw new DomainError('reset_used', 'Dieser Rücksetz-Link wurde schon benutzt oder durch einen neueren ersetzt.');
        }
        if ($reset['is_expired']) {
            throw new DomainError('reset_expired', 'Dieser Rücksetz-Link ist abgelaufen. Bitte einen neuen anfordern.');
        }

        $user = $this->users->findById($reset['user_id']);
        $creator = $this->users->findById($reset['created_by']);
        // Eine Meldung für alle Fälle: wer nur den Link hat, erfährt nichts über Sperren oder Rollen.
        if (
            $user === null || $user['status'] !== UserRepository::STATUS_ACTIVE
            || $creator === null || $creator['status'] !== UserRepository::STATUS_ACTIVE
            || !$this->managesAllGaragesOf($reset['created_by'], $reset['user_id'])
        ) {
            throw new DomainError('reset_used', 'Dieser Rücksetz-Link ist nicht mehr gültig. Bitte einen neuen anfordern.');
        }

        return $user;
    }

    /** Ein Konto ohne Garage verwaltet niemand: sonst bliebe der Link gültig, nachdem das Mitglied entfernt wurde. */
    private function managesAllGaragesOf(int $managerId, int $userId): bool
    {
        $targetRoles = $this->garages->rolesFor($userId);
        if ($targetRoles === []) {
            return false;
        }

        $managerRoles = $this->garages->rolesFor($managerId);
        foreach (array_keys($targetRoles) as $garageId) {
            if (($managerRoles[$garageId] ?? null) !== Actor::ROLE_OWNER) {
                return false;
            }
        }

        return true;
    }
}
