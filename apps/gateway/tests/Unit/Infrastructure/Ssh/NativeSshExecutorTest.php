<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;

it('builds strict argument-safe SSH invocations', function (): void {
    $runner = new class implements ProcessRunner
    {
        public ?ProcessInvocation $invocation = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocation = $invocation;

            return new CommandResult(0, 'ok', '', 12, false);
        }
    };
    $executor = new NativeSshExecutor($runner);
    $connection = new SshConnection(
        host: '10.44.0.3',
        user: 'orbit',
        port: 22,
        identityFile: '/home/orbit/.orbit/ssh/id_ed25519',
        knownHostsFile: '/home/orbit/.orbit/ssh/known_hosts',
    );

    $result = $executor->execute(
        $connection,
        new RemoteCommand(['systemctl', 'restart', 'orbit-app; touch /tmp/unsafe']),
    );

    expect($result->succeeded())
        ->toBeTrue()
        ->and($runner->invocation?->arguments)
        ->toBe([
            'ssh',
            '-i',
            '/home/orbit/.orbit/ssh/id_ed25519',
            '-p',
            '22',
            '-o',
            'BatchMode=yes',
            '-o',
            'StrictHostKeyChecking=yes',
            '-o',
            'UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts',
            '-o',
            'ConnectTimeout=10',
            '-o',
            'ServerAliveInterval=5',
            '-o',
            'ServerAliveCountMax=2',
            '--',
            'orbit@10.44.0.3',
            "'systemctl' 'restart' 'orbit-app; touch /tmp/unsafe'",
        ])
        ->and($runner->invocation?->timeout)
        ->toBe(900.0);
});

it('passes protected stdin without adding its bytes to the local SSH invocation', function (): void {
    $sensitiveValue = 'ALPHA=opaque-value';
    $input = ProtectedInput::fromString($sensitiveValue);
    $runner = new class implements ProcessRunner
    {
        public ?ProcessInvocation $invocation = null;

        public ?string $inputHash = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocation = $invocation;

            if ($invocation->protectedInput instanceof ProtectedInput) {
                $this->inputHash = hash('sha256', stream_get_contents($invocation->protectedInput->stream()));
                $invocation->protectedInput->close();
            }

            return new CommandResult(0, 'ok', '', 1, false);
        }
    };
    $connection = new SshConnection(
        host: '10.44.0.3',
        user: 'orbit',
        port: 22,
        identityFile: '/home/orbit/.orbit/ssh/id_ed25519',
        knownHostsFile: '/home/orbit/.orbit/ssh/known_hosts',
    );

    new NativeSshExecutor($runner)->execute(
        $connection,
        new RemoteCommand(['cat'], protectedInput: $input),
    );

    $debugOutput = print_r($runner->invocation, return: true);

    expect($runner->invocation?->input)
        ->toBeNull()
        ->and($runner->invocation?->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class)
        ->and(implode("\0", $runner->invocation->arguments ?? []))
        ->not->toContain($sensitiveValue)->and($runner->inputHash)->toBe(hash('sha256', $sensitiveValue))->and(
            $debugOutput,
        )
        ->not->toContain($sensitiveValue)->toContain('[PROTECTED]');
});

it('passes invocation-local output and cancellation controls to the process runner', function (): void {
    $output = static function (ProcessOutput $output): void {};
    $cancelled = static fn (): bool => false;
    $runner = new class implements ProcessRunner
    {
        public ?ProcessInvocation $invocation = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocation = $invocation;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $connection = new SshConnection(
        host: '10.44.0.3',
        user: 'orbit',
        port: 22,
        identityFile: '/home/orbit/.orbit/ssh/id_ed25519',
        knownHostsFile: '/home/orbit/.orbit/ssh/known_hosts',
    );

    new NativeSshExecutor($runner)->execute(
        $connection,
        new RemoteCommand(['true'], output: $output, cancelled: $cancelled),
    );

    expect($runner->invocation?->output)
        ->toBe($output)
        ->and($runner->invocation?->cancelled)
        ->toBe($cancelled);
});

/** A runner that records the invocation and succeeds. */
function ssh_executor_recording_runner(): ProcessRunner
{
    return new class implements ProcessRunner
    {
        public ?ProcessInvocation $invocation = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocation = $invocation;

            return new CommandResult(0, '', '', 1, false);
        }
    };
}

function ssh_executor_connection(string $sshDirectory): SshConnection
{
    return new SshConnection(
        host: '10.44.0.3',
        user: 'orbit',
        port: 22,
        identityFile: "{$sshDirectory}/id_ed25519",
        knownHostsFile: "{$sshDirectory}/known_hosts",
    );
}

it('runs each command as a channel on the Node\'s shared connection', function (): void {
    $sshDirectory = '/tmp/omx-'.bin2hex(random_bytes(3));
    mkdir($sshDirectory, 0700);
    $runner = ssh_executor_recording_runner();

    try {
        new NativeSshExecutor($runner)->execute(ssh_executor_connection($sshDirectory), new RemoteCommand(['true']));

        expect(array_slice($runner->invocation?->arguments ?? [], 17, 7))
            ->toBe([
                '-o',
                'ControlMaster=auto',
                '-o',
                "ControlPath={$sshDirectory}/mux/%C",
                '-o',
                'ControlPersist=60s',
                '--',
            ])
            ->and(fileperms("{$sshDirectory}/mux") & 0777)
            ->toBe(0700);
    } finally {
        @rmdir("{$sshDirectory}/mux");
        @rmdir($sshDirectory);
    }
});

it('opens its own connection when the socket directory cannot hold a socket', function (): void {
    $runner = ssh_executor_recording_runner();
    $tooLong = '/tmp/'.str_repeat('a', 40);

    new NativeSshExecutor($runner)->execute(ssh_executor_connection($tooLong), new RemoteCommand(['true']));

    expect($runner->invocation?->arguments)
        ->not->toContain('ControlMaster=auto')
        ->and(is_dir("{$tooLong}/mux"))
        ->toBeFalse();
});

it('opens a new connection when the caller does not share one', function (): void {
    $sshDirectory = '/tmp/omx-'.bin2hex(random_bytes(3));
    mkdir($sshDirectory, 0700);
    $runner = ssh_executor_recording_runner();
    $connection = new SshConnection(
        host: '10.44.0.3',
        user: 'orbit',
        port: 22,
        identityFile: "{$sshDirectory}/id_ed25519",
        knownHostsFile: "{$sshDirectory}/known_hosts",
        shareConnection: false,
    );

    try {
        new NativeSshExecutor($runner)->execute($connection, new RemoteCommand(['true']));

        expect($runner->invocation?->arguments)->not->toContain('ControlMaster=auto');
    } finally {
        @rmdir("{$sshDirectory}/mux");
        @rmdir($sshDirectory);
    }
});
