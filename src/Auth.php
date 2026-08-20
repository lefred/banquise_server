<?php
declare(strict_types=1);

/**
 * Represents the signed-in user (or a guest) for the current request and
 * answers every "may this user do X" question from one capability table,
 * so authorization never has to be re-derived ad hoc at each call site.
 */
final class BanquiseAuth
{
    public const ROLES = ['administrator', 'fleet_manager', 'fleet_viewer', 'plugin_manager'];

    private const CAPABILITIES = [
        // View the fleet, server detail, and observed plugin state.
        'fleet.view' => ['administrator', 'fleet_manager', 'fleet_viewer'],
        // Approve/disable/delete/rename servers and queue plugin tasks on them.
        'fleet.manage' => ['administrator', 'fleet_manager'],
        // Add, edit, refresh, and delete signed catalog entries.
        'catalog.write' => ['administrator', 'plugin_manager'],
        // Triage public plugin submissions: status, internal comments, publish.
        'submissions.review' => ['administrator', 'plugin_manager'],
        // Create/disable users and assign roles.
        'users.manage' => ['administrator'],
        // Enrollment mode and dedicated enrollment tokens.
        'enrollment.manage' => ['administrator'],
        // Public/private catalog distribution mode.
        'distribution.manage' => ['administrator'],
        // Local vs. external (read-only mirror) catalog source.
        'catalog_source.manage' => ['administrator'],
    ];

    /** @param string[] $roles */
    public function __construct(
        public readonly ?int $userId,
        public readonly string $email,
        public readonly string $displayName,
        private readonly array $roles,
    ) {}

    public static function guest(): self
    {
        return new self(null, '', '', []);
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @return string[] */
    public function roles(): array
    {
        return $this->roles;
    }

    public function can(string $capability): bool
    {
        if (!$this->isAuthenticated()) return false;
        foreach (self::CAPABILITIES[$capability] ?? [] as $role) {
            if ($this->hasRole($role)) return true;
        }
        return false;
    }

    /** @throws RuntimeException if the current user lacks the capability. */
    public function require(string $capability): void
    {
        if (!$this->can($capability)) {
            throw new RuntimeException('You do not have permission to perform this action.');
        }
    }
}
