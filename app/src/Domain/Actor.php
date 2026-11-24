<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use PDO;

/**
 * Wer handelt: ein Mensch im Browser, ein Agent per Token oder der Deploy.
 * Jede Abfrage auf Fakten hängt die Garagen dieses Akteurs selbst an (Regel 3 in AGENTS.md).
 */
final readonly class Actor
{
    public const ROLE_OWNER = 'inhaber';
    public const ROLE_MEMBER = 'mitglied';
    public const ROLE_READONLY = 'lesend';
    public const ROLES = [self::ROLE_OWNER, self::ROLE_MEMBER, self::ROLE_READONLY];
    private const WRITING_ROLES = [self::ROLE_OWNER, self::ROLE_MEMBER];

    public const VIA_WEB = 'web';
    public const VIA_MCP = 'mcp';
    public const VIA_OAUTH = 'oauth';
    public const VIA_DEPLOY = 'deploy';
    public const VIA_CALENDAR = 'calendar';

    /**
     * @param list<string> $scopes
     * @param array<int, string> $roles garage_id => Rolle
     */
    private function __construct(
        public ?int $userId,
        public string $name,
        public string $via,
        public array $scopes,
        public array $roles,
    ) {
    }

    public static function deploy(): self
    {
        return new self(null, 'deploy', self::VIA_DEPLOY, [], []);
    }

    /** Für Vorgänge ohne bekannten Nutzer, etwa einen fehlgeschlagenen Login; ohne Rollen, also ohne Zugriff. */
    public static function anonymous(): self
    {
        return new self(null, 'anonym', self::VIA_WEB, [], []);
    }

    /**
     * Abruf des Kalender-Abos ohne Anmeldung: die Signatur des Links berechtigt genau diese eine Garage, nur zum Lesen
     * (ADR-024). Ohne Nutzer und ohne Schreibrecht.
     */
    public static function calendar(int $garageId): self
    {
        return new self(null, 'kalender:' . $garageId, self::VIA_CALENDAR, [Scopes::READ], [$garageId => self::ROLE_READONLY]);
    }

    /** @param list<string> $scopes */
    public static function forUser(int $userId, string $via, string $name, array $scopes, PDO $pdo): self
    {
        return new self($userId, $name, $via, $scopes, (new GarageRepository($pdo))->rolesFor($userId));
    }

    /** @return list<int> */
    public function garageIds(): array
    {
        return array_keys($this->roles);
    }

    public function canRead(int $garageId): bool
    {
        return isset($this->roles[$garageId]);
    }

    public function isOwner(int $garageId): bool
    {
        return ($this->roles[$garageId] ?? null) === self::ROLE_OWNER;
    }

    public function canWrite(int $garageId): bool
    {
        $role = $this->roles[$garageId] ?? null;

        return $role !== null
            && in_array($role, self::WRITING_ROLES, true)
            && in_array(Scopes::WRITE, $this->scopes, true);
    }
}
