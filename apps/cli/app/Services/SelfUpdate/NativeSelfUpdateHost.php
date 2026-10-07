<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use App\Support\EffectiveUser;
use Phar;

final readonly class NativeSelfUpdateHost implements SelfUpdateHost
{
    /** The first line the Gateway's agent converge writes into the unit it owns. */
    public const string AgentUnitMarker = '# Managed by Orbit: agent';

    public function __construct(
        private string $agentUnitPath,
        private string $agentBinaryPath,
        /** `PHP_BINARY`, which the static PHP inside a standalone binary leaves empty. */
        private string $phpBinary = PHP_BINARY,
    ) {}

    public function runningBinary(): RunningBinary
    {
        $phar = Phar::running(false);

        if ($phar === '') {
            return new RunningBinary(RunningBinaryKind::Source, null);
        }

        $path = realpath($phar);

        if ($path === false || ! is_file($path)) {
            return new RunningBinary(RunningBinaryKind::Phar, $phar);
        }

        // A standalone binary is a static PHP that runs the PHAR appended to itself, so the process executable is
        // the PHAR file. A PHAR started by a separate `php` has that `php` as its executable. On Linux the kernel
        // names the executable; elsewhere the static PHP reports an empty PHP_BINARY.
        $executable = match (true) {
            PHP_OS_FAMILY === 'Linux' => @realpath('/proc/self/exe'),
            $this->phpBinary === '' => $path,
            default => @realpath($this->phpBinary),
        };

        return new RunningBinary($executable === $path ? RunningBinaryKind::Binary : RunningBinaryKind::Phar, $path);
    }

    public function platform(): ?string
    {
        $machine = strtolower(php_uname('m'));

        return match (true) {
            PHP_OS_FAMILY === 'Linux' && in_array($machine, ['x86_64', 'amd64'], true) => 'linux-x86_64',
            PHP_OS_FAMILY === 'Linux' && in_array($machine, ['aarch64', 'arm64'], true) => 'linux-aarch64',
            PHP_OS_FAMILY === 'Darwin' && $machine === 'arm64' => 'macos-arm64',
            default => null,
        };
    }

    public function isRoot(): bool
    {
        return EffectiveUser::id() === 0;
    }

    public function isManagedNode(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_file($this->agentUnitPath) || ! is_readable($this->agentUnitPath)) {
            return false;
        }

        $handle = @fopen($this->agentUnitPath, 'rb');

        if ($handle === false) {
            return false;
        }

        $firstLine = fgets($handle, 256);
        fclose($handle);

        return is_string($firstLine) && rtrim($firstLine, "\r\n") === self::AgentUnitMarker;
    }

    public function agentBinaryPath(): string
    {
        return $this->agentBinaryPath;
    }

    public function agentOwner(): array
    {
        return [0, 0];
    }

    public function exitAfterReplacingItself(int $status): never
    {
        exit($status);
    }
}
