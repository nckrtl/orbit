<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Which physical standby a checkout owns.
 *
 * A checkout is a primary: it builds, refreshes, and promotes one set of
 * standby VMs and records the promoted generation in its own `.e2e/standby/`.
 * Two primaries on one host must not share those VMs, or a promotion from one
 * leaves the other's manifest naming snapshots that no longer exist. The
 * namespace makes the physical resources distinct: the repository's primary
 * checkout owns the unnamespaced standby, and the validation clone that
 * `bin/e2e-live` drives owns the `live` one.
 *
 * The namespace is an allowlist, not free text: every standby needs its own
 * deterministic `10.232.<slot>.0/24` subnet, and a feature topology must never
 * be handed a slot a standby holds.
 */
final readonly class StandbyIdentity
{
    /**
     * Every standby namespace this host supports, with the network slot it owns.
     *
     * @var array<string, int>
     */
    private const array SLOTS = ['' => 1, 'live' => 200];

    private function __construct(
        public string $namespace,
        public int $slot,
    ) {}

    /** The standby of the repository's own primary checkout. */
    public static function primary(): self
    {
        return new self('', self::SLOTS['']);
    }

    /** The standby of the validation clone `bin/e2e-live` drives. */
    public static function live(): self
    {
        return new self('live', self::SLOTS['live']);
    }

    public static function forNamespace(?string $namespace): self
    {
        $namespace = $namespace === null ? '' : trim($namespace);
        if (! array_key_exists($namespace, self::SLOTS)) {
            throw new InvalidArgumentException(
                'The standby namespace is unknown; use one of: '
                .implode(', ', array_map(
                    static fn (string $known): string => $known === '' ? '(empty)' : $known,
                    array_keys(self::SLOTS),
                ))
                .'.',
            );
        }

        return new self($namespace, (int) self::SLOTS[$namespace]);
    }

    /**
     * Every standby the host may hold, whichever checkout owns it. Capacity,
     * the orphan network sweep, and legacy retirement all read this: resources
     * of a standby another checkout owns are never free to take or delete.
     *
     * @return list<self>
     */
    public static function known(): array
    {
        return array_map(self::forNamespace(...), array_keys(self::SLOTS));
    }

    public function isPrimary(): bool
    {
        return $this->namespace === '';
    }

    public function network(): string
    {
        return $this->namespace === '' ? 'oe-standby' : 'oe-'.$this->namespace.'-standby';
    }

    public function instancePrefix(): string
    {
        return $this->namespace === '' ? 'orbit-e2e-standby-' : 'orbit-e2e-'.$this->namespace.'-standby-';
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
