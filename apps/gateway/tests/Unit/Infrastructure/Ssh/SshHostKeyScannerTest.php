<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshHostKeyScanner;

it('scans and fingerprints the preferred SSH host key', function (): void {
    $runner = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            if (count($this->invocations) === 1) {
                return new CommandResult(
                    0,
                    "[94.237.40.75]:2222 ssh-rsa RSAKEY\n[94.237.40.75]:2222 ssh-ed25519 EDKEY\n",
                    '',
                    5,
                    false,
                );
            }

            return new CommandResult(0, '256 SHA256:abc root@node (ED25519)', '', 2, false);
        }
    };
    $scanner = new SshHostKeyScanner($runner, ssh_host_key_scanner_unused_ssh());

    $key = $scanner->scan('94.237.40.75', 2222);

    expect($key->type)
        ->toBe('ssh-ed25519')
        ->and($key->value)
        ->toBe('EDKEY')
        ->and($key->fingerprint)
        ->toBe('SHA256:abc')
        ->and($runner->invocations[0]->arguments)
        ->toBe([
            'ssh-keyscan',
            '-T',
            '10',
            '-p',
            '2222',
            '--',
            '94.237.40.75',
        ])
        ->and($runner->invocations[1]->input)
        ->toBe("[94.237.40.75]:2222 ssh-ed25519 EDKEY\n");
});

it('keeps bounded command diagnostics when the host key scan fails', function (): void {
    $result = new CommandResult(
        255,
        '',
        'ssh-keyscan failed',
        15_000,
        true,
    );
    $runner = new class($result) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(
            private readonly CommandResult $result,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return $this->result;
        }
    };
    $scanner = new SshHostKeyScanner($runner, ssh_host_key_scanner_unused_ssh());
    $exception = null;

    try {
        $scanner->scan('192.0.2.63', 22);
    } catch (SshHostKeyScanException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(SshHostKeyScanException::class)
        ->and($exception?->result)
        ->toBe($result)
        ->and($runner->invocations)
        ->toHaveCount(1);
});

it('scans on the jump host for a host that only the jump host reaches', function (): void {
    $runner = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return new CommandResult(0, '256 SHA256:guest root@guest (ED25519)', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor
    {
        /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
        public array $calls = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = ['connection' => $connection, 'command' => $command];

            return new CommandResult(0, "10.251.77.32 ssh-ed25519 GUESTKEY\n", '', 5, false);
        }
    };
    $jump = new SshConnection(
        host: '10.44.0.7',
        user: 'nckrtl',
        port: 22,
        identityFile: '/home/orbit/.orbit/ssh/id_ed25519',
        knownHostsFile: '/home/orbit/.orbit/ssh/known_hosts',
    );

    $key = new SshHostKeyScanner($runner, $ssh)->scan('10.251.77.32', 22, $jump);

    expect($key->fingerprint)
        ->toBe('SHA256:guest')
        ->and($ssh->calls)
        ->toHaveCount(1)
        ->and($ssh->calls[0]['connection'])
        ->toBe($jump)
        ->and($ssh->calls[0]['command']->arguments)
        ->toBe(['ssh-keyscan', '-T', '10', '-p', '22', '--', '10.251.77.32'])
        ->and($ssh->calls[0]['command']->timeout)
        ->toBe(15.0)
        ->and($runner->invocations)
        ->toHaveCount(1)
        ->and($runner->invocations[0]->arguments)
        ->toBe(['ssh-keygen', '-lf', '-', '-E', 'sha256'])
        ->and($runner->invocations[0]->input)
        ->toBe("10.251.77.32 ssh-ed25519 GUESTKEY\n");
});

/** An SSH executor that fails the test when a scan without a jump host uses SSH. */
function ssh_host_key_scanner_unused_ssh(): SshExecutor
{
    return new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw new LogicException('A scan without a jump host must not use SSH.');
        }
    };
}
