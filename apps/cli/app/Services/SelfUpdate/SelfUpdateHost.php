<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

/** The facts about this machine that `orbit self-update` acts on. */
interface SelfUpdateHost
{
    public function runningBinary(): RunningBinary;

    /** The release platform of this machine: `linux-x86_64`, `linux-aarch64`, `macos-arm64`, or null. */
    public function platform(): ?string;

    public function isRoot(): bool;

    /**
     * Whether the Gateway manages this machine's `orbit-agent`: a Linux host whose agent unit carries the
     * marker that the Gateway's agent converge writes.
     */
    public function isManagedNode(): bool;

    public function agentBinaryPath(): string;

    /**
     * The user and group IDs the agent binary must have: root, as the Gateway's agent converge installs it.
     *
     * @return array{int, int}
     */
    public function agentOwner(): array;

    /**
     * Ends the process after `orbit self-update` replaced the binary that runs it. The binary reads its own code
     * by path, and that path now holds the new binary, so the process must not load more code.
     */
    public function exitAfterReplacingItself(int $status): void;
}
