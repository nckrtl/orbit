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

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->ran[] = $invocation->arguments;
        $probe = Cache::lock(GatewaySchedulerHandoff::TickLock, 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $this->tickLockFree[] = $free;

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

/**
 * @return array{GatewayRuntimeHandoff, HandoffProcessRunner, FakeDocumentCleanup, FakeNodeCaddyBuilds, RecordingHandoffUnits}
 */
function runtime_handoff(?string $livePool = null): array
{
    $processes = new HandoffProcessRunner;
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
            scheduler: new GatewaySchedulerHandoff($processes, HANDOFF_APP, tickWaitSeconds: 1, startWaitSeconds: 1, sleep: static function (): void {
                usleep(50_000);
            }),
            cleanup: new GatewayCleanupHandoff($cleanup, generationWaitSeconds: 1, sleep: static function (): void {
                usleep(50_000);
            }),
            applicationPath: HANDOFF_APP,
            orbitHome: $orbitHome,
            readLivePool: static fn (): string => $livePool ?? $rendered,
        ),
        $processes,
        $cleanup,
        $builds,
        $units,
    ];
}

describe('gateway:release:handoff', function (): void {
    it('restarts the scheduler under the tick lock, resumes cleanup in the new generation, and leaves FPM alone', function (): void {
        $gateway = handoff_gateway();
        $scheduler = handoff_scheduler($gateway);
        [$handoff, $processes, $cleanup, $builds, $units] = runtime_handoff();
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
            ->and($builds->built)->toBe(['gateway'])
            ->and($units->converged)->toBe(['units', 'units'])
            ->and($processes->systemctl())->toBe([
                "sudo systemctl stop {$unit}",
                "sudo systemctl start {$unit}",
                "systemctl is-active --quiet {$unit}",
            ])
            ->and(array_filter($processes->ran, static fn (array $arguments): bool => in_array('php-fpm8.5', $arguments, true)))->toBe([])
            ->and($cleanup->calls)->toContain('reconcile', 'resume:r1');

        // The tick lock is held from the stop until the unit is active, and free again afterwards.
        $held = array_map(static fn (bool $free): bool => ! $free, $processes->tickLockFree);
        expect($held)->toBe([true, true, true])
            ->and(Cache::lock(GatewaySchedulerHandoff::TickLock, 1)->get())->toBeTrue();
    });

    it('reloads FPM only when the rendered pool differs from the live pool', function (): void {
        handoff_gateway();
        [$handoff, $processes] = runtime_handoff(livePool: "[orbit-gateway]\nchdir = /old\n");

        $result = $handoff->run();
        $commands = array_map(static fn (array $arguments): string => implode(' ', $arguments), $processes->ran);

        expect($result['fpm'])->toBe('reloaded')
            ->and($commands)->toContain('sudo systemctl reload-or-restart php8.5-fpm')
            ->and(array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'php-fpm8.5 --test'))))->toHaveCount(1);
    });

    it('reports a Gateway without a scheduler Process and does not restart anything for it', function (): void {
        $gateway = handoff_gateway();
        handoff_scheduler($gateway, directory: '/srv/other');
        [$handoff, $processes, $cleanup] = runtime_handoff();

        $result = $handoff->run();

        expect($result['scheduler'])->toBe('not_found')
            ->and($result['scheduler_unit'])->toBeNull()
            ->and($result['cleanup'])->toBe('running')
            ->and($processes->systemctl())->toBe([])
            ->and($cleanup->calls)->not->toContain('reconcile');
    });

    it('refuses to restart the scheduler while a tasks tick holds its lock', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $processes] = runtime_handoff();
        $tick = Cache::lock(GatewaySchedulerHandoff::TickLock, 300);
        expect($tick->get())->toBeTrue();

        $exception = release_failure(fn () => $handoff->run());
        $tick->release();

        expect($exception->errorCode)->toBe('gateway.release_scheduler_busy')
            ->and($exception->step)->toBe('handoff')
            ->and($processes->systemctl())->toBe([]);
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
