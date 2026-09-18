<?php

declare(strict_types=1);

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Processes\StopProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Event;

final class ProcessRecordEventsFakeRuntime implements ProcessRuntimeManager
{
    public function assertCanStart(Process $process): void {}

    public function converge(#[SensitiveParameter] Process $process): void {}

    public function start(Process $process): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void {}

    public function status(Process $process): string
    {
        return 'running';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}

beforeEach(function (): void {
    $this->runtime = new ProcessRecordEventsFakeRuntime;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->targets = new ProcessTargetResolver;

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
});

describe('Process record events', function (): void {
    it('broadcasts process.created when a new process is added', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new AddProcessData(
            targetType: ProcessTargetType::AppInstance,
            targetId: $this->instance->id,
            name: 'queue',
            runtime: ProcessRuntime::Systemd,
            command: ['/usr/bin/php', 'artisan', 'queue:work'],
            image: null,
            workingDirectory: null,
            environment: [],
            ports: [],
            volumes: [],
            restartPolicy: 'on-failure',
            start: false,
        );

        $result = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))
            ->execute($data);

        expect($result['created'])->toBeTrue();
        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessCreated
                && $event->id === $result['process']->id
                && $event->data['name'] === 'queue',
        );
    });

    it('does not broadcast when re-asserting an existing process with identical configuration', function (): void {
        $data = new AddProcessData(
            targetType: ProcessTargetType::AppInstance,
            targetId: $this->instance->id,
            name: 'queue',
            runtime: ProcessRuntime::Systemd,
            command: ['/usr/bin/php', 'artisan', 'queue:work'],
            image: null,
            workingDirectory: null,
            environment: [],
            ports: [],
            volumes: [],
            restartPolicy: 'on-failure',
            start: false,
        );
        new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);

        Event::fake([RecordBroadcast::class]);

        $result = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))
            ->execute($data);

        expect($result['created'])->toBeFalse();
        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('broadcasts process.status when a process is started', function (): void {
        $process = Process::query()->create([
            'owner_type' => AppInstance::class,
            'owner_id' => $this->instance->id,
            'name' => 'web',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/home/orbit/apps/docs',
            'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'serve']],
            'restart_policy' => 'always',
            'desired_state' => DesiredProcessState::Stopped,
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new StartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($process);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessStatus
                && $event->id === $process->id
                && $event->data['runtime_status'] === 'running',
        );
    });

    it('broadcasts process.status when a process is stopped', function (): void {
        $process = Process::query()->create([
            'owner_type' => AppInstance::class,
            'owner_id' => $this->instance->id,
            'name' => 'web',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/home/orbit/apps/docs',
            'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'serve']],
            'restart_policy' => 'always',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new StopProcessAction($this->runtime)->execute($process);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessStatus
                && $event->id === $process->id,
        );
    });

    it('broadcasts process.deleted with a minimal snapshot when a process is removed', function (): void {
        $process = Process::query()->create([
            'owner_type' => AppInstance::class,
            'owner_id' => $this->instance->id,
            'name' => 'web',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/home/orbit/apps/docs',
            'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'serve']],
            'restart_policy' => 'always',
            'desired_state' => DesiredProcessState::Stopped,
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new RemoveProcessAction($this->runtime, $this->targets)->execute($process);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessDeleted
                && $event->id === $process->id
                && $event->data === ['id' => $process->id, 'name' => 'web'],
        );
    });
});
