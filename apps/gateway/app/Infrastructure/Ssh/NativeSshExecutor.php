<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final readonly class NativeSshExecutor implements SshExecutor
{
    /** How long a shared connection stays open after its last command (ADR 0127). */
    public const string ControlPersist = '60s';

    /**
     * The longest socket directory that still leaves room for OpenSSH's 40-character `%C` name,
     * a slash, and the temporary suffix it adds while creating the socket, inside the 104-byte
     * Unix socket path limit.
     */
    private const int MaxSocketDirectoryLength = 40;

    public function __construct(
        private ProcessRunner $runner,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return $this->runner->run(new ProcessInvocation(
            arguments: [
                'ssh',
                '-i',
                $connection->identityFile,
                '-p',
                (string) $connection->port,
                '-o',
                'BatchMode=yes',
                '-o',
                'StrictHostKeyChecking=yes',
                '-o',
                "UserKnownHostsFile={$connection->knownHostsFile}",
                '-o',
                "ConnectTimeout={$connection->connectTimeout}",
                '-o',
                'ServerAliveInterval=5',
                '-o',
                'ServerAliveCountMax=2',
                ...$this->multiplexing($connection),
                '--',
                "{$connection->user}@{$connection->host}",
                $command->shellCommand(),
            ],
            timeout: $command->timeout ?? $connection->commandTimeout,
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
            output: $command->output,
            cancelled: $command->cancelled,
            terminateGraceSeconds: $command->terminateGraceSeconds,
        ));
    }

    /**
     * Options that run the command as a channel on the Node's shared connection, or none when
     * the socket directory cannot hold a socket, so the command opens its own connection.
     *
     * @return list<string>
     */
    private function multiplexing(SshConnection $connection): array
    {
        $directory = dirname($connection->identityFile).'/mux';

        if (strlen($directory) > self::MaxSocketDirectoryLength || ! $this->socketDirectory($directory)) {
            return [];
        }

        return [
            '-o',
            'ControlMaster=auto',
            '-o',
            "ControlPath={$directory}/%C",
            '-o',
            'ControlPersist='.self::ControlPersist,
        ];
    }

    private function socketDirectory(string $directory): bool
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
            return false;
        }

        return (fileperms($directory) & 0777) === 0700 || @chmod($directory, 0700);
    }
}
