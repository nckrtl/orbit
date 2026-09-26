<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Logs\LogStreamBroadcast;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** @return array{Node, AppInstance, TaskGroup} */
function publish_group(): array
{
    $node = Node::query()->create([
        'name' => 'publish-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.71', 'wireguard_ip' => '10.44.0.71',
    ]);
    $app = OrbitApp::query()->create(['name' => 'Publish', 'slug' => 'publish', 'repository_url' => 'git@example.test:publish.git', 'default_branch' => 'main']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/publish/task-1', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Publish', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'lines_added' => 1, 'lines_deleted' => 1, 'line_diff' => 2]);
    $group->taskable()->associate($instance)->save();
    app(TaskBroadcasts::class)->flush();

    return [$node, $instance, $group];
}

/** @param array<string, mixed>|null $diff */
function publish_view(Node $node, AppInstance $instance, ?array $diff): void
{
    app(CacheAgentStateView::class)->putNode($node->id, [], 'available', 3, CacheAgentStateView::now(), null, [$instance->id => [
        'instance_id' => $instance->id, 'base' => 'main', 'start' => null, 'branch' => 'task-1', 'head' => str_repeat('d', 40),
        'dirty' => false, 'commits' => null, 'diff' => $diff,
    ]]);
}

/** @return list<RecordBroadcast> */
function publish_events(RecordEventType $type): array
{
    $events = [];
    Event::assertDispatched(RecordBroadcast::class, function (RecordBroadcast $event) use (&$events, $type): bool {
        if ($event->type === $type) {
            $events[] = $event;
        }

        return true;
    });

    return $events;
}

describe('orbit:agent-view-publish', function (): void {
    beforeEach(fn () => Event::fake([RecordBroadcast::class]));

    it('stores complete line counts and sends one group notice', function (): void {
        [$node, $instance, $group] = publish_group();
        publish_view($node, $instance, ['files' => 2, 'added' => 30, 'removed' => 4, 'truncated' => false]);

        $this->artisan('orbit:agent-view-publish', ['--workspace' => ["{$node->id}:{$instance->id}"]])->assertSuccessful();

        expect(publish_events(RecordEventType::TaskGroupUpdated)[0]->data ?? null)
            ->toBe(['id' => $group->id, 'status' => 'running', 'lines_added' => 30, 'lines_deleted' => 4, 'line_diff' => 34]);
    });

    it('still sends the notice for a new head whose diff is over the limits, and keeps the stored counts', function (?array $diff): void {
        [$node, $instance, $group] = publish_group();
        publish_view($node, $instance, $diff);

        $this->artisan('orbit:agent-view-publish', ['--workspace' => ["{$node->id}:{$instance->id}"]])->assertSuccessful();

        expect(publish_events(RecordEventType::TaskGroupUpdated))->toHaveCount(1)
            ->and($group->fresh()?->line_diff)->toBe(2);
    })->with([
        'truncated' => [['files' => 5500, 'added' => 0, 'removed' => 0, 'truncated' => true]],
        'unknown base' => [null],
    ]);

    it('ignores a workspace of an Instance on another Node', function (): void {
        [$node, $instance] = publish_group();
        $other = Node::query()->create(['name' => 'other', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.72', 'wireguard_ip' => '10.44.0.72']);
        publish_view($other, $instance, ['files' => 1, 'added' => 999, 'removed' => 0, 'truncated' => false]);

        $this->artisan('orbit:agent-view-publish', ['--workspace' => ["{$other->id}:{$instance->id}"]])->assertSuccessful();

        Event::assertNotDispatched(RecordBroadcast::class, static fn (RecordBroadcast $event): bool => $event->type === RecordEventType::TaskGroupUpdated);
    });

    it('broadcasts one Process usage sample', function (): void {
        app()->instance(ProcessUsageIndex::class, new class implements ProcessUsageIndex
        {
            public function usage(Collection $processes): array
            {
                return [];
            }
        });

        $this->artisan('orbit:agent-view-publish', ['--usage' => '1790000000'])->assertSuccessful();

        $events = publish_events(RecordEventType::ProcessUsage);
        expect($events)->toHaveCount(1)
            ->and($events[0]->id)->toBe(1_790_000_000)
            ->and($events[0]->data)->toBe(['part' => 1, 'parts' => 1, 'processes' => []]);
    });
});

/** Runs one `--logs` relay run with this standard input and returns its exit code and output. */
function publish_logs(string $input): array
{
    $command = app(Kernel::class)->all()['orbit:agent-view-publish'];
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, $input);
    rewind($stdin);
    $arguments = new ArrayInput(['--logs' => true]);
    $arguments->setStream($stdin);
    $output = new BufferedOutput;

    return [$command->run($arguments, $output), $output->fetch()];
}

describe('orbit:agent-view-publish --logs', function (): void {
    beforeEach(fn () => Event::fake([LogStreamBroadcast::class]));

    it('relays the batch on standard input and prints the open stream count', function (): void {
        activate_websocket_role();
        [$code, $output] = publish_logs((string) json_encode(['relay' => 'relay-1', 'items' => [['type' => 'agent_left', 'item' => 1, 'node' => 3]], 'sweep' => true]));

        expect($code)->toBe(0)
            ->and(trim($output))->toBe('{"open_streams":0}');
    });

    it('fails on a batch it cannot read, so the subscriber runs it again', function (string $input): void {
        [$code, $output] = publish_logs($input);

        expect($code)->toBe(1)
            ->and($output)->toContain('not valid');
    })->with([
        'not JSON' => ['{'],
        'no relay' => ['{"items":[],"sweep":false}'],
        'unknown item' => ['{"relay":"r","items":[{"type":"shout","item":1,"node":3}],"sweep":false}'],
        'lines that are not strings' => ['{"relay":"r","items":[{"type":"lines","item":1,"node":3,"stream":"ab","lines":[1],"dropped":0,"skipped":0}],"sweep":false}'],
    ]);
});
