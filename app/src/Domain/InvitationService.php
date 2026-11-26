<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use Garage\Auth\UserSession;
use Garage\Db\Transaction;
use Garage\Util\Env;
use PDO;
use PDOException;

/**
 * Konten entstehen nur per Einladung (ADR-006): Deploy-Token oder ein Inhaber (ADR-026) erzeugt, der Eingeladene nimmt an
 * und legt sein Konto an — oder tritt mit seinem bestehenden Konto bei.
 */
final class InvitationService
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const DEFAULT_DAYS = 7;
    private const MAX_PASSWORD_BYTES = 72;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 190;
    private const MAX_DAYS = 30;
    private const TOKEN_PREFIX = 'ginv_';
    private const DUPLICATE_KEY_ERROR = 1062;

    private InvitationRepository $invitations;
    private UserRepository $users;
    private GarageRepository $garages;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->invitations = new InvitationRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->garages = new GarageRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Entweder `$garageId` (Mitglied einer bestehenden Garage) oder `$garageName` (legt die Garage an, nur als Inhaber).
     *
     * @return array{id: int, link: string, expires_at: string}
     * @throws DomainError `invalid_value`
     */
    public function create(?int $garageId, ?string $garageName, string $role, ?string $email, int $days, Actor $actor): array
    {
        $this->assertValidTarget($garageId, $garageName, $role);
        $this->assertValidTerms($email, $days);

        return Transaction::run($this->pdo, fn (): array => $this->issue($garageId, $garageName, $role, $email, $days, $actor));
    }

    /**
     * Einladung eines Inhabers in seine Garage. Die Sperre verhindert, dass ein gerade herabgestufter Inhaber noch einen
     * Link erzeugt, den der Widerruf beim Herabstufen nicht mehr sieht.
     *
     * @return array{id: int, link: string, expires_at: string}
     * @throws DomainError `not_found`, `forbidden`, `invalid_value`
     */
    public function createForGarage(int $garageId, string $role, ?string $email, int $days, Actor $actor): array
    {
        GarageAccess::assertOwner($garageId, $actor);
        $this->assertValidTarget($garageId, null, $role);
        $this->assertValidTerms($email, $days);

        return Transaction::run($this->pdo, function () use ($garageId, $role, $email, $days, $actor): array {
            GarageAccess::lockOwnersAsOwner($this->garages, $garageId, $actor);

            return $this->issue($garageId, null, $role, $email, $days, $actor);
        });
    }

    /** @throws DomainError `not_found`, `forbidden` */
    public function revoke(int $garageId, int $invitationId, Actor $actor): void
    {
        GarageAccess::assertOwner($garageId, $actor);

        Transaction::run($this->pdo, function () use ($garageId, $invitationId, $actor): void {
            if (!$this->invitations->revoke($invitationId, $garageId)) {
                throw new DomainError('not_found', 'Eine offene Einladung mit dieser ID gibt es in der Garage nicht.', ['invitation_id' => $invitationId]);
            }
            $this->audit->record($actor, 'invitation.revoke', 'invitation:' . $invitationId, $garageId);
        });
    }

    /**
     * Beitritt mit einem bestehenden Konto. Wer schon Mitglied ist, verbraucht die Einladung nicht.
     *
     * @return array{garage_id: int, garage_name: string, role: string}
     * @throws DomainError `invitation_not_found`, `invitation_used`, `invitation_expired`, `already_member`
     */
    public function join(string $token, Actor $actor): array
    {
        try {
            return Transaction::run($this->pdo, fn (): array => $this->addToGarage($token, $actor));
        } catch (PDOException $exception) {
            // Zwei Einladungen derselben Garage gleichzeitig: der Primärschlüssel von garage_members fängt die zweite.
            if (($exception->errorInfo[1] ?? null) === self::DUPLICATE_KEY_ERROR) {
                throw $this->alreadyMember();
            }

            throw $exception;
        }
    }

    /** @return array{garage_id: int, garage_name: string, role: string} */
    private function addToGarage(string $token, Actor $actor): array
    {
        $invitation = $this->invitations->findByHash(hash('sha256', $token), true);
        $this->assertUsable($invitation);

        $userId = (int) $actor->userId;
        $garageId = $invitation['garage_id'];
        if ($garageId !== null && $actor->canRead($garageId)) {
            throw $this->alreadyMember();
        }
        $garageId ??= $this->garages->insert((string) $invitation['garage_name']);

        $this->garages->addMember($garageId, $userId, $invitation['role']);
        $this->invitations->markAccepted($invitation['id'], $userId);
        $this->audit->record($actor, 'invitation.join', 'invitation:' . $invitation['id'], $garageId, null, ['role' => $invitation['role']]);

        return [
            'garage_id' => $garageId,
            'garage_name' => (string) ($invitation['resolved_garage_name'] ?? $invitation['garage_name']),
            'role' => $invitation['role'],
        ];
    }

    private function alreadyMember(): DomainError
    {
        return new DomainError('already_member', 'Du bist schon Mitglied dieser Garage.');
    }

    /**
     * Läuft in der Transaktion des Aufrufers.
     *
     * @return array{id: int, link: string, expires_at: string}
     */
    private function issue(?int $garageId, ?string $garageName, string $role, ?string $email, int $days, Actor $actor): array
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $invitationId = $this->invitations->insert(hash('sha256', $token), $garageId, $garageName, $role, $email, $days, $actor->userId);
        $this->audit->record($actor, 'invitation.create', 'invitation:' . $invitationId, $garageId, null, ['role' => $role, 'created_by' => $actor->userId]);

        return [
            'id' => $invitationId,
            'link' => Env::get('GARAGE_BASE_URL') . '/garage/einladung.html#' . $token,
            'expires_at' => $this->invitations->expiresAt($invitationId),
        ];
    }

    /**
     * @return array{garage_name: string, role: string, expires_at: string}
     * @throws DomainError `invitation_not_found`, `invitation_used`, `invitation_expired`
     */
    public function preview(string $token): array
    {
        $invitation = $this->invitations->findByHash(hash('sha256', $token), false);
        $this->assertUsable($invitation);

        return [
            'garage_name' => (string) ($invitation['resolved_garage_name'] ?? $invitation['garage_name']),
            'role' => $invitation['role'],
            'expires_at' => $invitation['expires_at'],
        ];
    }

    /**
     * Legt Konto, Mitgliedschaft und bei Bedarf die Garage an und startet die Sitzung.
     *
     * @return array{access_token: string, expires_in: int, user: array{id: int, name: string, email: string}, refresh_token: string}
     * @throws DomainError `invalid_value`, `invitation_not_found`, `invitation_used`, `invitation_expired`, `email_taken`
     */
    public function accept(string $token, string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $this->assertValidAccount($name, $email, $password);

        // Der Hash läuft vor der Transaktion: bcrypt dauert, und die Sperre auf der Einladung soll kurz sein.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $user = Transaction::run($this->pdo, fn (): array => $this->createAccount($token, $name, $email, $passwordHash));
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === self::DUPLICATE_KEY_ERROR) {
                throw new DomainError('email_taken', 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.', ['field' => 'email']);
            }

            throw $exception;
        }

        return (new UserSession($this->pdo))->start($user);
    }

    /** @return array{id: int, name: string, email: string} */
    private function createAccount(string $token, string $name, string $email, string $passwordHash): array
    {
        $invitation = $this->invitations->findByHash(hash('sha256', $token), true);
        $this->assertUsable($invitation);

        if ($this->users->findByEmail($email) !== null) {
            throw new DomainError('email_taken', 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.', ['field' => 'email']);
        }

        $userId = $this->users->insert($email, $name, $passwordHash);
        $garageId = $invitation['garage_id'] ?? $this->garages->insert((string) $invitation['garage_name']);
        $this->garages->addMember($garageId, $userId, $invitation['role']);
        $this->invitations->markAccepted($invitation['id'], $userId);

        $actor = Actor::forUser($userId, Actor::VIA_WEB, 'user:' . $userId, Scopes::ALL, $this->pdo);
        $this->audit->record($actor, 'invitation.accept', 'invitation:' . $invitation['id'], $garageId, null, ['role' => $invitation['role']]);

        return ['id' => $userId, 'name' => $name, 'email' => $email];
    }

    /**
     * @param array{id: int, garage_id: int|null, garage_name: string|null, role: string, expires_at: string, is_expired: bool, is_accepted: bool, is_revoked: bool, resolved_garage_name: string|null}|null $invitation
     * @phpstan-assert !null $invitation
     */
    private function assertUsable(?array $invitation): void
    {
        if ($invitation === null) {
            throw new DomainError('invitation_not_found', 'Diese Einladung gibt es nicht. Bitte den Link prüfen oder eine neue Einladung anfordern.');
        }
        if ($invitation['is_accepted']) {
            throw new DomainError('invitation_used', 'Diese Einladung wurde schon angenommen.');
        }
        if ($invitation['is_revoked']) {
            throw new DomainError('invitation_used', 'Diese Einladung wurde widerrufen. Bitte eine neue anfordern.');
        }
        if ($invitation['is_expired']) {
            throw new DomainError('invitation_expired', 'Diese Einladung ist abgelaufen. Bitte eine neue anfordern.');
        }
    }

    private function assertValidTarget(?int $garageId, ?string $garageName, string $role): void
    {
        if (!in_array($role, Actor::ROLES, true)) {
            throw new DomainError('invalid_value', 'role muss inhaber, mitglied oder lesend sein.', ['field' => 'role']);
        }

        if (($garageId === null) === ($garageName === null)) {
            throw new DomainError('invalid_value', 'Genau eines von garage_id und garage_name ist nötig.', ['field' => 'garage_id']);
        }

        if ($garageName !== null) {
            if (trim($garageName) === '' || mb_strlen($garageName) > self::MAX_NAME_LENGTH) {
                throw new DomainError('invalid_value', 'garage_name braucht 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen.', ['field' => 'garage_name']);
            }
            if ($role !== Actor::ROLE_OWNER) {
                throw new DomainError('invalid_value', 'Eine neue Garage hat ihren ersten Nutzer als inhaber.', ['field' => 'role']);
            }

            return;
        }

        if (!$this->garages->exists((int) $garageId)) {
            throw new DomainError('invalid_value', 'Diese Garage gibt es nicht.', ['field' => 'garage_id']);
        }
    }

    private function assertValidTerms(?string $email, int $days): void
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new DomainError('invalid_value', 'days muss zwischen 1 und ' . self::MAX_DAYS . ' liegen.', ['field' => 'days']);
        }
        if ($email !== null && mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new DomainError('invalid_value', 'email darf höchstens ' . self::MAX_EMAIL_LENGTH . ' Zeichen lang sein.', ['field' => 'email']);
        }
    }

    private function assertValidAccount(string $name, string $email, string $password): void
    {
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new DomainError('invalid_value', 'name braucht 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen.', ['field' => 'name']);
        }
        if (strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainError('invalid_value', 'email ist keine gültige E-Mail-Adresse.', ['field' => 'email']);
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new DomainError('invalid_value', 'Das Passwort braucht mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen.', ['field' => 'password']);
        }
        // bcrypt liest nur die ersten 72 Bytes; ein längeres Passwort würde still gekürzt.
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new DomainError('invalid_value', 'Das Passwort darf höchstens ' . self::MAX_PASSWORD_BYTES . ' Bytes lang sein.', ['field' => 'password']);
        }
    }
}
