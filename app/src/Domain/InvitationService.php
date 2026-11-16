<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use Garage\Auth\UserSession;
use Garage\Db\Transaction;
use Garage\Util\Env;
use PDO;
use PDOException;

/** Konten entstehen nur per Einladung (ADR-006): Deploy-Token erzeugt, der Eingeladene nimmt an und legt sein Konto an. */
final class InvitationService
{
    public const MIN_PASSWORD_LENGTH = 12;
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
     * @return array{link: string, expires_at: string}
     * @throws DomainError `invalid_value`
     */
    public function create(?int $garageId, ?string $garageName, string $role, ?string $email, int $days, Actor $actor): array
    {
        $this->assertValidTarget($garageId, $garageName, $role);
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new DomainError('invalid_value', 'days muss zwischen 1 und ' . self::MAX_DAYS . ' liegen.', ['field' => 'days']);
        }

        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));

        $invitationId = Transaction::run($this->pdo, function () use ($token, $garageId, $garageName, $role, $email, $days, $actor): int {
            $invitationId = $this->invitations->insert(hash('sha256', $token), $garageId, $garageName, $role, $email, $days);
            $this->audit->record($actor, 'invitation.create', 'invitation:' . $invitationId, $garageId, null, ['role' => $role]);

            return $invitationId;
        });

        return [
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
     * @param array{id: int, garage_id: int|null, garage_name: string|null, role: string, expires_at: string, is_expired: bool, is_accepted: bool, resolved_garage_name: string|null}|null $invitation
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
