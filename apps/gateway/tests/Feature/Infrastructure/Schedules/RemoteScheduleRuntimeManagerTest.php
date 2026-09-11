<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Schedule;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->ssh = new ScheduleRuntimeFakeSshExecutor;
    $this->manager = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleRenderer(new ScheduleRuntimeFakeCertificates, 'https://10.44.0.1'),
        $this->ssh,
        new ScheduleRuntimeFakeKeys,
        new ScheduleRuntimeFakeKnownHosts,
    );
    $this->node = Node::query()->create([
        'name' => 'node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $this->node->id,
        'host_node_id' => $this->node->id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'printf "sensitive command"',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Provisioning,
    ]);
});

it('installs through one locked protected-input transaction with fixed safe argv', function (): void {
    $this->manager->install($this->schedule);
    $command = $this->ssh->commands[1];

    expect($this->ssh->commands[0]->arguments)->toBe([
        'sudo',
        '-u',
        'orbit',
        'test',
        '-d',
        '/home/orbit',
    ]);

    expect($command->arguments)->toBe([
        'sudo',
        'bash',
        '-s',
        '--',
        $this->schedule->id,
        'orbit',
        'enabled',
    ])->and(json_encode($command->arguments, JSON_THROW_ON_ERROR))
        ->not->toContain($this->schedule->command)
        ->and($command->input)->toBeNull()
        ->and($command->protectedInput)->not->toBeNull();

    $program = stream_get_contents($command->protectedInput?->stream());
    expect($program)->toContain('flock --wait 30')
        ->toContain('cleanup() { rm -rf -- "$work"; }')
        ->toContain('trap cleanup EXIT')
        ->toContain("rollback() {\n  set +e\n  systemctl disable --now \"\$timer\"")
        ->toContain('systemd-analyze calendar')
        ->toContain('systemd-analyze verify')
        ->not->toContain($this->schedule->command);
});

it('uses the exact service for non-blocking runs and bounded newest complete logs', function (): void {
    $this->ssh->responses = [
        schedule_runtime_result(),
        schedule_runtime_result(),
        schedule_runtime_result(stdout: "newest\nolder\n"),
    ];

    $this->manager->run($this->schedule);
    $logs = $this->manager->logs($this->schedule, 25);

    expect($this->ssh->commands[1]->arguments)->toBe([
        'sudo',
        'systemctl',
        'start',
        '--no-block',
        'orbit-schedule-'.$this->schedule->id.'.service',
    ])->and($this->ssh->commands[2]->arguments)->toBe([
        'sudo',
        'journalctl',
        '--unit',
        'orbit-schedule-'.$this->schedule->id.'.service',
        '--lines',
        '25',
        '--no-pager',
        '--output',
        'cat',
        '--reverse',
    ])->and($this->ssh->commands[2]->timeout)->toBe(10.0)
        ->and($logs->output)->toBe("older\nnewest\n")
        ->and($logs->truncated)->toBeFalse();
});

it('retains standalone intent when direct service inspection finds an active command', function (): void {
    $this->ssh->responses = [schedule_runtime_result(exitCode: 75)];

    expect($this->manager->remove($this->schedule, false))->toBeFalse()
        ->and($this->ssh->commands[0]->arguments)->toBe([
            'sudo',
            'bash',
            '-s',
            '--',
            $this->schedule->id,
            'orbit',
            'standalone',
        ]);

    $program = stream_get_contents($this->ssh->commands[0]->protectedInput?->stream());
    expect($program)
        ->toContain('systemctl is-active "$service"')
        ->toContain("case \"\$service_state\" in ''|inactive|failed|unknown) ;; *) exit 75 ;; esac");
});

it('maps root certificate rendering failures to the bounded install error', function (): void {
    $manager = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleRenderer(new ScheduleRuntimeFailingCertificates),
        $this->ssh,
        new ScheduleRuntimeFakeKeys,
        new ScheduleRuntimeFakeKnownHosts,
    );

    expect(fn () => $manager->install($this->schedule))
        ->toThrow(function (ScheduleOperationException $exception): void {
            expect($exception->step)->toBe('render')
                ->and($exception->error)->toBe(ScheduleErrorCode::InstallFailed)
                ->and($exception->getMessage())->toBe('Schedule installation failed.');
        })
        ->and($this->ssh->commands)->toHaveCount(1);
});

function schedule_runtime_result(
    int $exitCode = 0,
    string $stdout = '',
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 1, $truncated);
}

final class ScheduleRuntimeFakeSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<CommandResult> */
    public array $responses = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return array_shift($this->responses) ?? schedule_runtime_result();
    }
}

final class ScheduleRuntimeFakeKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class ScheduleRuntimeFakeKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class ScheduleRuntimeFakeCertificates implements LeafCertificateSigner
{
    public function sign(string $hostname, string $certificateRequest): string
    {
        throw new LogicException('Signing is not part of Schedule runtime tests.');
    }

    public function rootCertificate(): string
    {
        return 'TEST ROOT CERTIFICATE';
    }
}

final class ScheduleRuntimeFailingCertificates implements LeafCertificateSigner
{
    public function sign(string $hostname, string $certificateRequest): string
    {
        throw new LogicException('Signing is not part of Schedule runtime tests.');
    }

    public function rootCertificate(): string
    {
        throw new RuntimeException('Sensitive root certificate failure.');
    }
}
