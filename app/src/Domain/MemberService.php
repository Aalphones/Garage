<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Mitglieder einer Garage (ADR-026). Eine Garage behält immer mindestens einen Inhaber: jede Änderung sperrt zuerst die
 * Inhaber-Zeilen, so laufen zwei gleichzeitige Änderungen nacheinander. Die Sperre muss der erste Lesezugriff der
 * Transaktion bleiben, sonst sehen spätere Abfragen den Stand von vor dem Warten.
 */
final class MemberService
{
    private GarageRepository $garages;
    private InvitationRepository $invitations;
    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->garages = new GarageRepository($pdo);
        $this->invitations = new InvitationRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Mitglieder sehen alle Mitglieder, offene Einladungen nur Inhaber.
     *
     * @return array{members: list<array{user_id: int, name: string, email: string, role: string, since: string, is_self: bool}>, invitations: list<array{id: int, role: string, email: string|null, expires_at: string, created_at: string, created_by_name: string|null}>, can_manage: bool}
     * @throws DomainError `not_found`
     */
    public function list(int $garageId, Actor $actor): array
    {
        GarageAccess::assertMember($garageId, $actor);

        $canManage = $actor->isOwner($garageId);
        $invitations = [];
        if ($canManage) {
            $invitations = $this->invitations->listOpen($garageId);
        }

        return [
            'members' => array_map(
                static fn (array $member): array => self::withSelfFlag($member, $actor),
                $this->garages->members($garageId)
            ),
            'invitations' => $invitations,
            'can_manage' => $canManage,
        ];
    }

    /**
     * @return array{user_id: int, name: string, email: string, role: string, since: string, is_self: bool}
     * @throws DomainError `not_found`, `forbidden`, `invalid_value`, `last_owner`
     */
    public function changeRole(int $garageId, int $userId, string $role, Actor $actor): array
    {
        GarageAccess::assertOwner($garageId, $actor);
        if (!in_array($role, Actor::ROLES, true)) {
            throw new DomainError('invalid_value', 'role muss inhaber, mitglied oder lesend sein.', ['field' => 'role']);
        }

        return Transaction::run($this->pdo, function () use ($garageId, $userId, $role, $actor): array {
            $ownerIds = GarageAccess::lockOwnersAsOwner($this->garages, $garageId, $actor);
            $member = $this->requireMember($garageId, $userId);
            if ($member['role'] === $role) {
                return self::withSelfFlag($member, $actor);
            }

            $losesOwnership = $member['role'] === Actor::ROLE_OWNER;
            if ($losesOwnership) {
                $this->assertOtherOwnerRemains($ownerIds, $userId);
            }

            $this->garages->changeRole($garageId, $userId, $role);
            $this->audit->record($actor, 'member.role', 'user:' . $userId, $garageId, ['role' => $member['role']], ['role' => $role]);
            if ($losesOwnership) {
                $this->revokeInvitationsBy($garageId, $userId, $actor);
            }
            $member['role'] = $role;

            return self::withSelfFlag($member, $actor);
        });
    }

    /**
     * Ein Inhaber entfernt jedes Mitglied, jedes Mitglied sich selbst („Garage verlassen“). Der Zugriff endet mit der
     * nächsten Anfrage, weil die Rollen jedes Mal neu gelesen werden.
     *
     * @throws DomainError `not_found`, `forbidden`, `last_owner`
     */
    public function remove(int $garageId, int $userId, Actor $actor): void
    {
        $isLeaving = $userId === $actor->userId;
        if ($isLeaving) {
            GarageAccess::assertMember($garageId, $actor);
        } else {
            GarageAccess::assertOwner($garageId, $actor);
        }

        Transaction::run($this->pdo, function () use ($garageId, $userId, $isLeaving, $actor): void {
            if ($isLeaving) {
                $ownerIds = $this->garages->ownerIdsForUpdate($garageId);
            } else {
                $ownerIds = GarageAccess::lockOwnersAsOwner($this->garages, $garageId, $actor);
            }

            $member = $this->requireMember($garageId, $userId);
            if ($member['role'] === Actor::ROLE_OWNER) {
                $this->assertOtherOwnerRemains($ownerIds, $userId);
            }

            $this->garages->removeMember($garageId, $userId);
            $this->audit->record($actor, 'member.remove', 'user:' . $userId, $garageId, ['role' => $member['role']]);
            $this->revokeInvitationsBy($garageId, $userId, $actor);
        });
    }

    /** @return array{user_id: int, name: string, email: string, role: string, since: string} */
    private function requireMember(int $garageId, int $userId): array
    {
        $member = $this->garages->member($garageId, $userId);
        if ($member === null) {
            throw new DomainError('not_found', 'Dieses Mitglied gibt es in der Garage nicht.', ['user_id' => $userId]);
        }

        return $member;
    }

    /** @param list<int> $ownerIds */
    private function assertOtherOwnerRemains(array $ownerIds, int $userId): void
    {
        if (array_diff($ownerIds, [$userId]) === []) {
            throw new DomainError('last_owner', 'Die Garage braucht mindestens einen Inhaber. Erst jemand anderen zum Inhaber machen.');
        }
    }

    // Sonst käme ein entfernter oder herabgestufter Inhaber mit einem selbst erzeugten Link wieder herein.
    private function revokeInvitationsBy(int $garageId, int $userId, Actor $actor): void
    {
        foreach ($this->invitations->openIdsCreatedBy($garageId, $userId) as $invitationId) {
            if ($this->invitations->revoke($invitationId, $garageId)) {
                $this->audit->record($actor, 'invitation.revoke', 'invitation:' . $invitationId, $garageId, null, ['creator_lost_ownership' => $userId]);
            }
        }
    }

    /**
     * @param array{user_id: int, name: string, email: string, role: string, since: string} $member
     * @return array{user_id: int, name: string, email: string, role: string, since: string, is_self: bool}
     */
    private static function withSelfFlag(array $member, Actor $actor): array
    {
        return [...$member, 'is_self' => $member['user_id'] === $actor->userId];
    }
}
