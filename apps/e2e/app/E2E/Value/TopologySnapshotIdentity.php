<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The repository's one persistent topology snapshot identity.
 *
 * The retired identity remains available only for the bounded migration from
 * the former standby terminology.
 */
final readonly class TopologySnapshotIdentity
{
    private function __construct(
        public int $slot,
        private bool $retired = false,
    ) {}

    /** The topology snapshot of the repository's own primary checkout. */
    public static function primary(): self
    {
        return new self(1);
    }

    /** Resolve the physical identity used before the topology snapshot rename. */
    public static function retired(): self
    {
        return new self(1, retired: true);
    }

    public function network(): string
    {
        return $this->retired ? 'oe-standby' : 'oe-topo-snap';
    }

    public function instancePrefix(): string
    {
        return $this->retired ? 'orbit-e2e-standby-' : 'orbit-e2e-topology-snapshot-';
    }

    public function instance(string $role): string
    {
        if (! in_array($role, TopologyProfile::ROLES, true)) {
            throw new InvalidArgumentException('The topology role is invalid.');
        }

        return $this->instancePrefix().$role;
    }

    /** @return list<string> */
    public function instances(): array
    {
        return array_map($this->instance(...), TopologyProfile::ROLES);
    }
}
