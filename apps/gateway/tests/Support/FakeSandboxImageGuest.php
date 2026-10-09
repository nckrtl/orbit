<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxImageGuest;
use App\Infrastructure\Ssh\HostKey;

/** A build VM that runs each started unit to the configured outcome on the next observation. */
final class FakeSandboxImageGuest implements SandboxImageGuest
{
    public bool $cloudInitDone = true;

    /** @var array<string, string> */
    public array $units = [];

    /** @var array<string, string> */
    public array $outcomes = [];

    /** @var array<string, array{script: string, argument: string}> */
    public array $started = [];

    /** @var array<string, array<string, string>>|null */
    public ?array $uploaded = null;

    /** @var array<string, array<string, string>> */
    public array $results = [];

    public int $cleans = 0;

    public ?string $cleanFailure = null;

    /** @var list<string> */
    public array $smoke = ['running', 'passed'];

    /** @var list<string> */
    public array $addresses = [];

    public function cloudInitDone(string $address, HostKey $key): bool
    {
        $this->addresses[] = $address;

        return $this->cloudInitDone;
    }

    public function unitState(string $address, HostKey $key, string $unit): string
    {
        $state = $this->units[$unit] ?? 'missing';
        if ($state === 'running') {
            $this->units[$unit] = $this->outcomes[$unit] ?? 'succeeded';
        }

        return $state;
    }

    public function startUnit(string $address, HostKey $key, string $unit, string $script, string $argument): void
    {
        $this->started[$unit] = ['script' => $script, 'argument' => $argument];
        $this->units[$unit] = 'running';
    }

    public function unitLog(string $address, HostKey $key, string $unit): string
    {
        return "E: Unable to locate package zfsutils-linux\n";
    }

    public function uploadCaches(string $address, HostKey $key, array $projects): void
    {
        $this->uploaded = $projects;
    }

    public function cacheResults(string $address, HostKey $key): array
    {
        return $this->results;
    }

    public function clean(string $address, HostKey $key, string $script): void
    {
        $this->cleans++;
        if ($this->cleanFailure !== null) {
            throw new ComputeException($this->cleanFailure, 'The clean step failed.');
        }
    }

    public function smokeState(string $address, HostKey $key): string
    {
        $this->addresses[] = $address;

        return count($this->smoke) > 1 ? array_shift($this->smoke) : $this->smoke[0];
    }
}
