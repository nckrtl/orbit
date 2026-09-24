<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

final readonly class SshConnection
{
    public function __construct(
        public string $host,
        public string $user,
        public int $port,
        public string $identityFile,
        public string $knownHostsFile,
        public int $connectTimeout = 10,
        public float $commandTimeout = 900.0,
        /**
         * Run the command on the Node's shared connection (ADR 0127). A reachability check turns this
         * off: a shared connection outlives a stopped sshd, so only a new connection shows whether the
         * Gateway can reach the Node now.
         */
        public bool $shareConnection = true,
    ) {}
}
