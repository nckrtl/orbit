<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\GatewayReleases\GatewayDocumentCleanup;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeNodeCaddyBuilds;

const HANDOFF_APP = '/home/orbit/orbit/apps/gateway';

final class HandoffProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $ran = [];

    /** @var list<bool> whether the tick lock was free at each command */
    public array $tickLockFree = [];

    /** @var array<string, CommandResult> results by the joined first three arguments */
    public array $results = [];

    /** @var list<string> what `systemctl show -p MainPID` reports, one entry per call; the last one repeats */
    public array $mainPids = ['4242', '0'];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->ran[] = $invocation->arguments;

        $probe = Cache::lock(GatewaySchedulerHandoff::TickLock, 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $this->tickLockFree[] = $free;

        if (array_slice($invocation->arguments, 0, 4) === ['systemctl', 'show', '-p', 'MainPID']) {
            $pid = count($this->mainPids) > 1 ? array_shift($this->mainPids) : $this->mainPids[0];

            return new CommandResult(0, $pid."\n", '', 1, false);
        }

        return $this->results[implode(' ', array_slice($invocation->arguments, 0, 3))]
            ?? new CommandResult(0, '', '', 1, false);
    }

    /** @return list<string> */
    public function systemctl(): array
    {
        return array_values(array_map(
            static fn (array $arguments): string => implode(' ', $arguments),
            array_filter($this->ran, static fn (array $arguments): bool => in_array('systemctl', $arguments, true)),
        ));
    }
}

final class FakeDocumentCleanup implements GatewayDocumentCleanup
{
    public bool $isConfigured = true;

    /** @var list<array{cleanup_state: string, cleanup_generation: string|null}> */
    public array $statuses = [];

    /** @var array{report_id?: string, report_state?: string, difference_count?: int, error_code?: string} */
    public array $report = ['report_id' => 'r1', 'report_state' => 'complete', 'difference_count' => 0];

    /** @var array{cleanup_state?: string, error_code?: string} */
    public array $resumed = ['cleanup_state' => 'running'];

    /** @var list<string> */
    public array $calls = [];

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function status(): array
    {
        $this->calls[] = 'status';

        return count($this->statuses) > 1 ? array_shift($this->statuses) : ($this->statuses[0] ?? ['cleanup_state' => 'running', 'cleanup_generation' => 'g1']);
    }

    public function reconcile(): array
    {
        $this->calls[] = 'reconcile';

        return $this->report;
    }

    public function resume(string $reportId): array
    {
        $this->calls[] = 'resume:'.$reportId;

        return $this->resumed;
    }
}

final class RecordingHandoffUnits implements AgentViewConverger, RuntimeHibernatorConverger
{
    /** @var list<string> */
    public array $converged = [];

    public function __construct(private readonly string $name) {}

    public function converge(): void
    {
        $this->converged[] = $this->name;
    }
}

function handoff_gateway(): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return $node;
}

function handoff_scheduler(Node $node, string $directory = HANDOFF_APP, string $name = 'schedule-work'): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $directory,
        'runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'schedule:work'], 'environment_file' => ''],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

final class SchedulerDrainProbe
{
    public int $cleared = 0;

    /** @var list<string> */
    public array $opcacheScripts = [];

    /** @var list<int> open pool connections, one entry per look; the last one repeats */
    public array $connections = [0];

    /** @var list<string> */
    public array $members = ['/usr/bin/php8.5 artisan schedule:work', "sh -c '/usr/bin/php8.5' 'artisan' orbit:deploy-development-defaults > '/dev/null' 2>&1"];
}

/**
 * @return array{GatewayRuntimeHandoff, HandoffProcessRunner, FakeDocumentCleanup, FakeNodeCaddyBuilds, RecordingHandoffUnits, SchedulerDrainProbe}
 */
function runtime_handoff(?string $livePool = null, int $drainSeconds = 5): array
{
    $processes = new HandoffProcessRunner;
    $probe = new SchedulerDrainProbe;
    $cleanup = new FakeDocumentCleanup;
    $builds = new FakeNodeCaddyBuilds;
    $units = new RecordingHandoffUnits('units');
    $orbitHome = sys_get_temp_dir().'/orbit-handoff-'.bin2hex(random_bytes(4));
    $rendered = new GatewayFpmConfigRenderer()->renderPool(HANDOFF_APP, $orbitHome);

    return [
        new GatewayRuntimeHandoff(
            builds: $builds,
            fpmRenderer: new GatewayFpmConfigRenderer,
            fpm: new NativeGatewayFpmConverger($processes),
            files: new ProtectedFileWriter,
            hibernator: $units,
            agentView: $units,
            scheduler: new GatewaySchedulerHandoff(
                $processes,
                HANDOFF_APP,
                tickWaitSeconds: 1,
                startWaitSeconds: 1,
                sleep: static function (): void {
                    usleep(50_000);
                },
                drainSeconds: $drainSeconds,
                clearMutexes: static function () use ($probe): void {
                    $probe->cleared++;
                },
                members: static fn (): array => $probe->members,
            ),
            cleanup: new GatewayCleanupHandoff($cleanup, generationWaitSeconds: 1, sleep: static function (): void {
                usleep(50_000);
            }),
            applicationPath: HANDOFF_APP,
            orbitHome: $orbitHome,
            readLivePool: static fn (): string => $livePool ?? $rendered,
            resetOpcache: static function (string $script) use ($probe): string {
                $probe->opcacheScripts[] = $script;

                return '{"reset":true}';
            },
            fpmConnections: static fn (): int => count($probe->connections) > 1 ? array_shift($probe->connections) : $probe->connections[0],
            sleep: static function (): void {
                usleep(20_000);
            },
            idleWaitSeconds: 1,
        ),
        $processes,
        $cleanup,
        $builds,
        $units,
        $probe,
    ];
}

describe('gateway:release:handoff', function (): void {
    it('lets the old scheduler finish its running commands, starts it on the new release, and resumes cleanup', function (): void {
        $gateway = handoff_gateway();
        $scheduler = handoff_scheduler($gateway);
        [$handoff, $processes, $cleanup, $builds, $units, $probe] = runtime_handoff();
        $processes->mainPids = ['4242', '4242', '4242', '0'];
        $cleanup->statuses = [
            ['cleanup_state' => 'running', 'cleanup_generation' => 'g1'],
            ['cleanup_state' => 'running', 'cleanup_generation' => 'g1'],
            ['cleanup_state' => 'paused', 'cleanup_generation' => 'g2'],
        ];

        $result = $handoff->run();
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";

        expect($result)->toMatchArray([
            'caddy' => 'reloaded',
            'fpm' => 'unchanged',
            'scheduler' => 'restarted',
            'scheduler_unit' => $unit,
            'cleanup' => 'resumed',
            'agent_view' => 'restarted',
            'cleanup_paused' => false,
        ])
            ->and($result['scheduler_drain']['outcome'])->toBe('drained')
            ->and($result['scheduler_drain']['running'])->toBe([$probe->members[1]])
            ->and($builds->built)->toBe(['gateway'])
            ->and($units->converged)->toBe(['units', 'units'])
            ->and($processes->systemctl())->toBe([
                "systemctl show -p MainPID --value {$unit}",
                "sudo systemctl kill --kill-whom=main --signal=SIGTERM {$unit}",
                "systemctl show -p MainPID --value {$unit}",
                "systemctl show -p MainPID --value {$unit}",
                "systemctl show -p MainPID --value {$unit}",
                "sudo systemctl start {$unit}",
                "systemctl is-active --quiet {$unit}",
            ])
            ->and($probe->cleared)->toBe(0)
            ->and(array_filter($processes->ran, static fn (array $arguments): bool => in_array('php-fpm8.5', $arguments, true)))->toBe([])
            ->and($cleanup->calls)->toContain('reconcile', 'resume:r1');
    });

    it('stops the scheduler only after the drain limit, under the tick lock, and then clears the overlap mutexes', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $processes, , , , $probe] = runtime_handoff(drainSeconds: 0);
        $processes->mainPids = ['4242'];
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";

        $result = $handoff->run();
        $commands = $processes->systemctl();
        $stop = array_search("sudo systemctl stop {$unit}", $commands, true);
        $held = array_map(static fn (bool $free): bool => ! $free, array_slice($processes->tickLockFree, (int) array_search(['sudo', 'systemctl', 'stop', $unit], $processes->ran, true)));

        expect($result['scheduler_drain'])->toMatchArray(['outcome' => 'forced', 'stopped' => [$probe->members[1]]])
            ->and($stop)->toBeInt()
            ->and(array_slice($commands, (int) $stop))->toBe([
                "sudo systemctl stop {$unit}",
                "sudo systemctl start {$unit}",
                "systemctl is-active --quiet {$unit}",
            ])
            ->and($held)->toBe([true, true, true])
            ->and($probe->cleared)->toBe(1)
            ->and(Cache::lock(GatewaySchedulerHandoff::TickLock, 1)->get())->toBeTrue();
    });

    it('starts a scheduler that is not running without a drain', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff();
        $processes->mainPids = ['0'];
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";

        $result = $handoff->run();

        expect($result['scheduler_drain']['outcome'])->toBe('not_running')
            ->and($processes->systemctl())->toBe([
                "systemctl show -p MainPID --value {$unit}",
                "sudo systemctl start {$unit}",
                "systemctl is-active --quiet {$unit}",
            ]);
    });

    it('resets the pool OPcache with the release script once the pool serves no request, and defers it otherwise', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, , , , , $probe] = runtime_handoff();
        $probe->connections = [2, 1, 0];

        $reset = $handoff->run();
        [$busyPool, , , , , $busyProbe] = runtime_handoff();
        $busyProbe->connections = [1];
        $busy = $busyPool->run();

        expect($reset['opcache'])->toBe('reset')
            ->and($probe->opcacheScripts)->toBe([HANDOFF_APP.'/resources/fpm/opcache-reset.php'])
            ->and($probe->connections)->toBe([0])
            ->and($busy['opcache'])->toBe('deferred')
            ->and($busyProbe->opcacheScripts)->toBe([]);
    });

    it('reloads FPM only when the rendered pool differs from the live pool', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff(livePool: "[orbit-gateway]\nchdir = /old\n");

        $result = $handoff->run();
        $commands = array_map(static fn (array $arguments): string => implode(' ', $arguments), $processes->ran);

        expect($result['fpm'])->toBe('reloaded')
            ->and($commands)->toContain('sudo systemctl reload-or-restart php8.5-fpm')
            ->and(array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'php-fpm8.5 --test'))))->toHaveCount(1);
    });

    it('fails when the Gateway scheduler Process is missing, or runs outside the Gateway application path', function (): void {
        $gateway = handoff_gateway();
        [$handoff, $processes] = runtime_handoff();

        $missing = release_failure(fn () => $handoff->run());
        handoff_scheduler($gateway, directory: '/srv/other');
        $mismatch = release_failure(fn () => $handoff->run());

        expect($missing->errorCode)->toBe('gateway.release_scheduler_missing')
            ->and($mismatch->errorCode)->toBe('gateway.release_scheduler_mismatch')
            ->and($mismatch->getMessage())->toContain('/srv/other')
            ->and(array_filter($processes->systemctl(), static fn (string $command): bool => str_contains($command, 'orbit-process-')))->toBe([]);
    });

    it('restarts other Gateway Node Processes that run from the Gateway application', function (): void {
        $gateway = handoff_gateway();
        $scheduler = handoff_scheduler($gateway);
        $worker = handoff_scheduler($gateway, directory: '/home/orbit/orbit/apps/cli', name: 'cli-worker');
        $worker->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'orbit', 'watch'], 'environment_file' => '']]);
        handoff_scheduler($gateway, directory: '/srv/elsewhere', name: 'unrelated')->update(['runtime_config' => ['command' => ['sleep', 'infinity'], 'environment_file' => '']]);
        [$handoff, $processes] = runtime_handoff();

        $result = $handoff->run();

        expect($result['processes_restarted'])->toBe(["orbit-process-{$worker->id}-cli-worker.service"])
            ->and($processes->systemctl())->toContain("sudo systemctl try-restart orbit-process-{$worker->id}-cli-worker.service")
            ->and($result['scheduler_unit'])->toBe("orbit-process-{$scheduler->id}-schedule-work.service");
    });

    it('starts the scheduler again when the forced stop fails', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff(drainSeconds: 0);
        $processes->mainPids = ['4242'];
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";
        $processes->results['sudo systemctl stop'] = new CommandResult(1, '', 'stop failed', 1, false);

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_scheduler_failed')
            ->and(array_slice($processes->systemctl(), -1))->toBe(["sudo systemctl start {$unit}"]);
    });

    it('refuses to stop the scheduler after the drain limit while a tasks tick holds its lock', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff(drainSeconds: 0);
        $processes->mainPids = ['4242'];
        $tick = Cache::lock(GatewaySchedulerHandoff::TickLock, 300);
        expect($tick->get())->toBeTrue();

        $exception = release_failure(fn () => $handoff->run());
        $tick->release();

        expect($exception->errorCode)->toBe('gateway.release_scheduler_busy')
            ->and($exception->step)->toBe('handoff')
            ->and($processes->systemctl())->not->toContain("sudo systemctl stop orbit-process-{$scheduler->id}-schedule-work.service");
    });

    it('fails the handoff when the scheduler unit does not come back', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff();
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";
        $processes->results['systemctl is-active --quiet'] = new CommandResult(3, '', '', 1, false);

        $exception = release_failure(fn () => $handoff->run());

        expect($exception->errorCode)->toBe('gateway.release_scheduler_failed')
            ->and($processes->systemctl())->toContain("sudo systemctl start {$unit}")
            ->and(Cache::lock(GatewaySchedulerHandoff::TickLock, 1)->get())->toBeTrue();
    });

    it('fails the handoff with a release error when Caddy cannot publish', function (): void {
        handoff_gateway();
        [$handoff, , , $builds] = runtime_handoff();
        $builds->failNext('gateway', 'reload');

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_caddy_failed');
    });

    it('fails without a Gateway Node', function (): void {
        [$handoff] = runtime_handoff();

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_gateway_node_missing');
    });
});

describe(GatewayCleanupHandoff::class, function (): void {
    it('skips cleanup when document storage is not configured', function (): void {
        $cleanup = new FakeDocumentCleanup;
        $cleanup->isConfigured = false;

        expect(new GatewayCleanupHandoff($cleanup)->resume(null, true))->toBe(['outcome' => 'skipped', 'paused' => false])
            ->and($cleanup->calls)->toBe([]);
    });

    it('stays paused and reports why when the report has differences', function (): void {
        $cleanup = new FakeDocumentCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g2']];
        $cleanup->report = ['report_id' => 'r2', 'report_state' => 'complete', 'difference_count' => 2];

        expect(new GatewayCleanupHandoff($cleanup, sleep: static function (): void {})->resume('g1', true))->toBe([
            'outcome' => 'paused',
            'paused' => true,
            'error_code' => 'gateway.release_cleanup_unresolved',
            'report_id' => 'r2',
            'difference_count' => 2,
        ])->and($cleanup->calls)->not->toContain('resume:r2');
    });

    it('stays paused when the new scheduler never rotates the generation', function (): void {
        $cleanup = new FakeDocumentCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];

        expect(new GatewayCleanupHandoff($cleanup, generationWaitSeconds: 0, sleep: static function (): void {})->resume('g1', true))
            ->toBe(['outcome' => 'paused', 'paused' => true, 'error_code' => 'gateway.release_cleanup_generation_unchanged']);
    });

    it('reconciles and resumes a cleanup that is paused without a restart', function (): void {
        $cleanup = new FakeDocumentCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];

        expect(new GatewayCleanupHandoff($cleanup)->resume('g1', false))->toBe(['outcome' => 'resumed', 'paused' => false, 'report_id' => 'r1']);
    });

    it('stays paused when resume is refused', function (): void {
        $cleanup = new FakeDocumentCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];
        $cleanup->resumed = ['cleanup_state' => 'paused', 'error_code' => 'project_documents.cleanup_inventory_changed'];

        expect(new GatewayCleanupHandoff($cleanup)->resume('g1', false))
            ->toBe(['outcome' => 'paused', 'paused' => true, 'error_code' => 'project_documents.cleanup_inventory_changed', 'report_id' => 'r1']);
    });
});
