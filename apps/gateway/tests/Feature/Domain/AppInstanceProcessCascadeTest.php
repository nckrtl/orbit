<?php

declare(strict_types=1);

use App\Actions\Processes\CascadeAppInstanceProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

it('removes every AppInstance-owned Process in stable order and leaves other owners untouched', function (): void {
    $target = orb131_cascade_instance('target');
    $other = orb131_cascade_instance('other');
    $first = orb131_cascade_process($target->id, 'first', LifecycleStatus::Active);
    $second = orb131_cascade_process($target->id, 'second', LifecycleStatus::Failed);
    $third = orb131_cascade_process($target->id, 'third', LifecycleStatus::Removing);
    $otherInstance = orb131_cascade_process($other->id, 'other', LifecycleStatus::Active);
    $legacy = orb131_cascade_process($target->id, 'legacy', LifecycleStatus::Active, 'App\\Models\\Instance');
    $runtime = new Orb131CascadeRuntimeManager;

    new CascadeAppInstanceProcessesAction(new RemoveProcessAction($runtime, new ProcessTargetResolver))->execute(
        $target->id,
    );

    expect($runtime->removed)->toBe([$first->id, $second->id, $third->id]);
    $this->assertDatabaseMissing('processes', ['id' => $first->id]);
    $this->assertDatabaseMissing('processes', ['id' => $second->id]);
    $this->assertDatabaseMissing('processes', ['id' => $third->id]);
    $this->assertDatabaseHas('processes', ['id' => $otherInstance->id]);
    $this->assertDatabaseHas('processes', ['id' => $legacy->id]);
});

it('retains failed cleanup for retry and repeats only unfinished Process removal', function (): void {
    $target = orb131_cascade_instance('retry');
    $first = orb131_cascade_process($target->id, 'first', LifecycleStatus::Active);
    $second = orb131_cascade_process($target->id, 'second', LifecycleStatus::Failed);
    $third = orb131_cascade_process($target->id, 'third', LifecycleStatus::Removing);
    $runtime = new Orb131CascadeRuntimeManager;
    $runtime->failureId = $second->id;
    $cascade = new CascadeAppInstanceProcessesAction(new RemoveProcessAction($runtime, new ProcessTargetResolver));

    expect(fn () => $cascade->execute($target->id))
        ->toThrow(ProcessOperationException::class, 'Exact Process ownership could not be verified.');

    $this->assertDatabaseMissing('processes', ['id' => $first->id]);
    $this->assertDatabaseHas('processes', [
        'id' => $second->id,
        'status' => LifecycleStatus::Failed->value,
        'failed_step' => 'remove',
        'error_code' => 'process.ownership_conflict',
    ]);
    $this->assertDatabaseHas('processes', ['id' => $third->id]);

    $runtime->failureId = null;
    $cascade->execute($target->id);

    expect($runtime->removed)->toBe([$first->id, $second->id, $second->id, $third->id]);
    $this->assertDatabaseMissing('processes', [
        'owner_type' => AppInstance::class,
        'owner_id' => $target->id,
    ]);
});

function orb131_cascade_instance(string $suffix): AppInstance
{
    $octet = match ($suffix) {
        'target' => 50,
        'other' => 51,
        'retry' => 52,
    };
    $app = OrbitApp::query()->create([
        'name' => "Cascade {$suffix}",
        'slug' => "cascade-{$suffix}",
        'repository_url' => "https://example.test/cascade-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => "cascade-{$suffix}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$octet}",
        'wireguard_ip' => "10.44.0.{$octet}",
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => "/srv/orbit/apps/cascade-{$suffix}",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

function orb131_cascade_process(
    int $ownerId,
    string $name,
    LifecycleStatus $status,
    string $ownerType = AppInstance::class,
): Process {
    return Process::query()->create([
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => '/srv/orbit/apps/example',
        'runtime_config' => ['command' => ['/bin/true']],
        'restart_policy' => 'always',
        'desired_state' => 'stopped',
        'status' => $status,
    ]);
}

final class Orb131CascadeRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<int> */
    public array $removed = [];

    public ?int $failureId = null;

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void {}

    public function start(Process $process): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void
    {
        $this->removed[] = $process->id;

        if ($this->failureId === $process->id) {
            throw new ProcessOperationException(
                step: 'remove',
                errorCode: 'process.ownership_conflict',
                message: 'Exact Process ownership could not be verified.',
            );
        }
    }

    public function status(Process $process): string
    {
        return 'stopped';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}
