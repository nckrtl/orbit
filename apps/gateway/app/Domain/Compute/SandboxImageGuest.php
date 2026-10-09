<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Infrastructure\Ssh\HostKey;

/**
 * Commands the Gateway runs over SSH on a base template build or smoke VM (ADR 0204). Long work
 * runs in a named systemd unit, so each scheduler tick only starts it or reads its state.
 * Every method throws a {@see ComputeException} when the VM cannot be reached or answers badly.
 */
interface SandboxImageGuest
{
    /** True once cloud-init finished without errors. */
    public function cloudInitDone(string $address, HostKey $key): bool;

    /** The state of a named unit: `missing`, `running`, `succeeded`, or `failed`. */
    public function unitState(string $address, HostKey $key, string $unit): string;

    /** Starts the script as a named unit. A unit that already exists is left alone. */
    public function startUnit(string $address, HostKey $key, string $unit, string $script, string $argument): void;

    /** The last lines of a unit's journal, for a failure record. */
    public function unitLog(string $address, HostKey $key, string $unit): string;

    /**
     * Replaces the uploaded manifest and lock files.
     *
     * @param  array<string, array<string, string>>  $projects  slug => file name => contents
     */
    public function uploadCaches(string $address, HostKey $key, array $projects): void;

    /** @return array<string, array<string, string>> slug => tool => outcome, as the warm script recorded it */
    public function cacheResults(string $address, HostKey $key): array;

    /** Runs the clean phase in this connection; afterwards the Gateway key no longer opens the VM. */
    public function clean(string $address, HostKey $key, string $script): void;

    /**
     * `running` while cloud-init runs; `passed` when it finished without errors and the ZFS checkout
     * dataset is mounted at `/home/orbit/orbit`, owned by `orbit` with mode `0700`; otherwise `failed`.
     */
    public function smokeState(string $address, HostKey $key): string;
}
