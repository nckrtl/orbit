<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SelfUpdate\RunningBinary;
use App\Services\SelfUpdate\RunningBinaryKind;
use App\Services\SelfUpdate\SelfUpdateHost;

/** A machine for `orbit self-update` tests: files live in a temporary directory, and the owner is the test user. */
final class FakeSelfUpdateHost implements SelfUpdateHost
{
    public function __construct(
        public string $directory,
        public RunningBinaryKind $kind = RunningBinaryKind::Binary,
        public ?string $platform = 'linux-x86_64',
        public bool $root = false,
        public bool $managedNode = false,
    ) {}

    public function binaryPath(): string
    {
        return $this->directory.'/bin/orbit';
    }

    public function runningBinary(): RunningBinary
    {
        return new RunningBinary($this->kind, $this->kind === RunningBinaryKind::Source ? null : $this->binaryPath());
    }

    public function platform(): ?string
    {
        return $this->platform;
    }

    public function isRoot(): bool
    {
        return $this->root;
    }

    public function isManagedNode(): bool
    {
        return $this->managedNode;
    }

    public function agentBinaryPath(): string
    {
        return $this->directory.'/bin/orbit-agent';
    }

    public function agentOwner(): array
    {
        return [(int) getmyuid(), (int) getmygid()];
    }

    public ?int $exitedWith = null;

    /** Records the exit instead of ending the test process. */
    public function exitAfterReplacingItself(int $status): void
    {
        $this->exitedWith = $status;
    }
}
