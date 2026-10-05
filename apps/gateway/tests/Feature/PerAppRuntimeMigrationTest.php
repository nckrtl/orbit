<?php

declare(strict_types=1);

use App\Actions\Instances\MigrateAppRuntimeAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\AppRuntimeMigrationProjector;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

function runtime_migration_fixture(): array
{
    $project = Project::query()->create(['name' => 'Migration', 'slug' => 'migration', 'repository_url' => 'https://example.test/migration.git', 'root' => 'public']);
    $node = Node::query()->create(['name' => 'migration', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.20']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/migration/main', 'status' => 'source_resolved', 'vite_port' => 5173, 'agentation_port' => 5173]);
    DB::table('vite_port_assignments')->insert(['node_id' => $node->id, 'instance_id' => $instance->id, 'app' => 'web', 'port' => 5173]);
    DB::table('annotation_port_assignments')->insert(['node_id' => $node->id, 'instance_id' => $instance->id, 'app' => 'web', 'kind' => 'agentation_port', 'port' => 5173]);
    $projector = new class implements AppRuntimeMigrationProjector
    {
        public array $calls = [];

        public ?string $fail = null;

        public array $failures = [];

        public function prepare(Node $node, AppRuntimeMigration $migration): array
        {
            $this->calls[] = 'prepare';

            return ['running' => []];
        }

        public function activate(Node $node, AppRuntimeMigration $migration): void
        {
            $this->step('activate');
        }

        public function rollback(Node $node, AppRuntimeMigration $migration): void
        {
            $this->step('rollback');
        }

        public function cleanup(Node $node, AppRuntimeMigration $migration): void
        {
            $this->step('cleanup');
        }

        private function step(string $step): void
        {
            $this->calls[] = $step;
            if (($this->failures[0] ?? null) === $step) {
                array_shift($this->failures);
                throw new RuntimeException('injected '.$step.' failure');
            }
            if ($this->fail === $step) {
                $this->fail = null;
                throw new RuntimeException('injected '.$step.' failure');
            }
        }
    };
    $ports = Mockery::mock(VitePortRuntime::class);
    $ports->shouldReceive('selectPort')->andReturnUsing(static function (Node $node, int $preferred, array $excluded): int {
        while (in_array($preferred, $excluded, true)) {
            $preferred++;
        }

        return $preferred;
    });
    $source = Mockery::mock(AppDevSourceOperationLock::class);
    $source->shouldReceive('synchronized')->andReturnUsing(static fn (int $id, Closure $operation): mixed => $operation());
    $projection = Mockery::mock(DevelopmentProjectionOperationLock::class);
    $projection->shouldReceive('run')->andReturnUsing(static fn (Closure $operation): mixed => $operation());
    $environment = Mockery::mock(InstanceEnvironmentOperationLock::class);
    $environment->shouldReceive('run')->andReturnUsing(static fn (array $ids, Closure $operation): mixed => $operation());
    $admissions = Mockery::mock(ProcessAdmissionLock::class);
    $admissions->shouldReceive('run')->andReturnUsing(static fn (array $ids, Closure $operation): mixed => $operation());
    $leases = Mockery::mock(ProcessRuntimeLease::class);
    $leases->shouldReceive('run')->andReturnUsing(static fn (Process $process, Closure $operation): mixed => $operation($process));

    return [$node, $instance, new MigrateAppRuntimeAction($source, $projection, $environment, $admissions, $leases, $ports, $projector), $projector, $ports];
}

it('journals the legacy annotator store independently and preserves pending withdrawal ownership', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    DB::table('annotation_port_assignments')->delete();
    $instance->update(['agentation_port' => null, 'annotator_port' => 4848,
        'app_runtime' => ['web' => ['annotator_store_identity' => false, 'annotator_port' => 4848]]]);
    $process = Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'app' => 'web',
        'name' => 'annotator', 'runtime' => 'systemd', 'runtime_config' => ['preset' => 'annotator', 'command' => ['/usr/bin/false']],
        'working_directory' => '/srv/migration/main', 'restart_policy' => 'on-failure', 'status' => 'active',
        'endpoint_withdrawal_started_at' => now()]);
    $action->execute($node);
    expect(AppRuntimeMigration::query()->count())->toBe(0)
        ->and($instance->fresh()->usesAppStoreIdentity('web'))->toBeFalse()
        ->and($projector->calls)->toBe([]);
    $process->update(['endpoint_withdrawal_started_at' => null]);
    $projector->fail = 'cleanup';
    expect(fn () => $action->execute($node))->toThrow(RuntimeException::class);
    $journal = AppRuntimeMigration::query()->sole();
    expect($journal->phase)->toBe('published')
        ->and($journal->plan['stores'])->toHaveCount(1)
        ->and($instance->fresh()->usesAppStoreIdentity('web'))->toBeTrue();
    expect(fn () => app(RemoveInstanceAction::class)->execute($instance, false))
        ->toThrow(ResourceOperationException::class, 'Finish the Node runtime migration');
    expect(fn () => app(SynchronizeInstanceEnvironmentAction::class)->execute($instance))
        ->toThrow(ResourceOperationException::class, 'Finish the Node runtime migration');
    $action->execute($node);
    expect($journal->fresh()->phase)->toBe('complete')
        ->and($projector->calls)->toBe(['prepare', 'activate', 'cleanup', 'cleanup']);
    $ports->shouldNotHaveReceived('selectPort');
});

it('journals legacy Vite file and unit identity even without a port collision', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    DB::table('annotation_port_assignments')->delete();
    $instance->update(['agentation_port' => null, 'app_runtime' => ['web' => ['app_identity' => false, 'vite_port' => 5173]]]);
    Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'app' => 'web', 'name' => 'vp-dev',
        'runtime' => 'systemd', 'runtime_config' => ['preset' => 'vp-dev', 'command' => ['/usr/bin/false']],
        'working_directory' => '/srv/migration/main', 'restart_policy' => 'on-failure', 'status' => 'active']);
    $action->execute($node);
    $journal = AppRuntimeMigration::query()->sole();
    expect($journal->plan['vite_files'])->toBe([['instance_id' => $instance->id, 'app' => 'web',
        'old' => '/etc/orbit/vite/app-instance-'.$instance->id.'.env',
        'new' => '/etc/orbit/vite/app-instance-'.$instance->id.'-web.env']])
        ->and($journal->plan['processes'])->toHaveCount(1)
        ->and($instance->fresh()->usesAppViteIdentity('web'))->toBeTrue()
        ->and($instance->fresh()->usesAppRuntimeIdentity('web'))->toBeFalse()
        ->and($projector->calls)->toBe(['prepare', 'activate', 'cleanup']);
    $ports->shouldNotHaveReceived('selectPort');
});

it('publishes a per-app route endpoint migration only after projection succeeds and never recycles an old reservation', function (): void {
    [$node, $instance, $action, $projector] = runtime_migration_fixture();
    $action->execute($node);
    $journal = AppRuntimeMigration::query()->sole();
    expect($journal->phase)->toBe('complete')->and($journal->published_at)->not->toBeNull()
        ->and($projector->calls)->toBe(['prepare', 'activate', 'cleanup'])
        ->and($instance->fresh()->runtimeForApp('web')['agentation_port'])->toBe(4747)
        ->and($instance->fresh()->runtimeForApp('web')['vite_port'])->toBe(5173)
        ->and(DB::table('annotation_port_assignments')->value('port'))->toBe(4747);
    $action->execute($node);
    expect(AppRuntimeMigration::query()->count())->toBe(1)->and($projector->calls)->toHaveCount(3);
});

it('rolls back a per-app route endpoint migration before publication and retries its identical recorded plan', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    $projector->fail = 'activate';
    expect(fn () => $action->execute($node))->toThrow(RuntimeException::class);
    $before = AppRuntimeMigration::query()->sole();
    expect($before->phase)->toBe('reserved')->and($before->published_at)->toBeNull()
        ->and($instance->fresh()->runtimeForApp('web')['agentation_port'])->toBe(5173)
        ->and(DB::table('annotation_port_assignments')->value('port'))->toBe(5173);
    $action->execute($node);
    expect(AppRuntimeMigration::query()->sole()->id)->toBe($before->id)
        ->and(AppRuntimeMigration::query()->sole()->plan)->toBe($before->plan)
        ->and($projector->calls)->toBe(['prepare', 'activate', 'rollback', 'prepare', 'activate', 'cleanup']);
    $ports->shouldHaveReceived('selectPort')->once();
});

it('finishes per-app route migration cleanup forward after publication without allocating or rolling back again', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    $projector->fail = 'cleanup';
    expect(fn () => $action->execute($node))->toThrow(RuntimeException::class);
    expect(AppRuntimeMigration::query()->sole()->phase)->toBe('published')->and($instance->fresh()->runtimeForApp('web')['agentation_port'])->toBe(4747);
    $action->execute($node);
    expect($projector->calls)->toBe(['prepare', 'activate', 'cleanup', 'cleanup'])->and(AppRuntimeMigration::query()->sole()->phase)->toBe('complete');
    $ports->shouldHaveReceived('selectPort')->once();
});

it('keeps a failed prepublication rollback journal and resumes the exact endpoint plan', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    $projector->failures = ['activate', 'rollback'];
    expect(fn () => $action->execute($node))->toThrow(RuntimeException::class, 'injected rollback failure');
    $journal = AppRuntimeMigration::query()->sole();
    $plan = $journal->plan;
    expect($journal->phase)->toBe('rollback_required')
        ->and($journal->published_at)->toBeNull()
        ->and($instance->fresh()->runtimeForApp('web')['agentation_port'])->toBe(5173)
        ->and(DB::table('annotation_port_assignments')->value('port'))->toBe(5173);
    $action->execute($node);
    expect($journal->fresh()->phase)->toBe('complete')
        ->and($journal->fresh()->plan)->toBe($plan)
        ->and($projector->calls)->toBe(['prepare', 'activate', 'rollback', 'rollback', 'prepare', 'activate', 'cleanup']);
    $ports->shouldHaveReceived('selectPort')->once();
});

it('refuses colliding retained per-app route endpoint owners before mutation and keeps their pending withdrawals', function (): void {
    [$node, $instance, $action, $projector, $ports] = runtime_migration_fixture();
    foreach (['vp-dev', 'agentation-mcp'] as $index => $preset) {
        Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'app' => 'web', 'name' => 'endpoint-'.$index, 'runtime' => 'systemd', 'runtime_config' => ['preset' => $preset, 'command' => ['/usr/bin/false']], 'working_directory' => '/srv/migration/main', 'restart_policy' => 'on-failure', 'status' => 'active', 'endpoint_withdrawal_started_at' => now()]);
    }
    try {
        $action->execute($node);
        test()->fail('Retained collision was accepted.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('app.port_migration_conflict')->and($error->status)->toBe(409)->and($error->details['owners'])->toContain('process:');
    }
    expect(AppRuntimeMigration::query()->count())->toBe(0)->and($projector->calls)->toBe([])
        ->and(Process::query()->whereNotNull('endpoint_withdrawal_started_at')->count())->toBe(2)
        ->and(DB::table('annotation_port_assignments')->value('port'))->toBe(5173);
    $ports->shouldNotHaveReceived('selectPort');
});
