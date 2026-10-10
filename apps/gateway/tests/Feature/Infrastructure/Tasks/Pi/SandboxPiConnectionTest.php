<?php

declare(strict_types=1);

use App\Actions\Compute\ReserveSandboxPiTokenAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{Instance, TaskSandbox, AgentThread} */
function sandbox_pi_workspace(?Node $host = null, int $port = 22000): array
{
    $host ??= Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'settings' => ['pi' => ['token' => 'host-secret', 'url' => 'http://host.test:3774']]]);
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'Orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Sandbox', 'brief' => 'Work', 'status' => 'running', 'task_compute' => TaskCompute::Vm]);
    $sandbox = TaskSandbox::query()->create(['id' => (string) Str::uuid(), 'group_id' => $group->id, 'provider' => 'incus',
        'name' => 'ot-'.$group->id, 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pi_port' => $port]]);
    app(ReserveSandboxPiTokenAction::class)->execute($sandbox);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [], 'blocked_networks' => ['192.168.0.0/16']]], 'orbit.pi.token' => 'global-secret']);
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'node_id' => $host->id, 'driver' => 'pi',
        'runtime_key' => 'sandbox:'.$sandbox->id, 'external_id' => 'session-'.$group->id, 'role' => 'implementer']);

    return [$workspace, $sandbox, $thread];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

describe('sandbox Pi identity', function (): void {
    it('encrypts and hides tokens, reuses them on retry, and separates groups on one host', function (): void {
        [$first, $one, $threadOne] = sandbox_pi_workspace();
        [$second, $two, $threadTwo] = sandbox_pi_workspace($first->node, 22001);
        $token = $one->pi_token;
        app(ReserveSandboxPiTokenAction::class)->execute($one);
        expect($one->pi_token)->toBe($token)->not->toBe($two->pi_token);
        expect($one->toArray())->not->toHaveKey('pi_token');
        expect(DB::table('task_sandboxes')->where('id', $one->id)->value('pi_token'))->not->toContain($token);
        Http::fake(['http://10.44.0.20:22000/sessions/*/messages' => Http::response(['duplicate' => false]),
            'http://10.44.0.20:22001/sessions/*/messages' => Http::response(['duplicate' => false])]);

        app(PiDriver::class)->send($threadOne, 'one', 'key-one');
        app(PiDriver::class)->send($threadTwo, 'two', 'key-two');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':22000/') && $request->hasHeader('Authorization', 'Bearer '.$one->pi_token));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':22001/') && $request->hasHeader('Authorization', 'Bearer '.$two->pi_token));
        Http::assertSentCount(2);
        expect($first->node->fresh()->settings['pi']['token'])->toBe('host-secret');
    });

    it('creates a sandbox session at its proxy and keeps the host token out of the request', function (string $model): void {
        [$workspace, $sandbox] = sandbox_pi_workspace();
        config(['orbit.pi.provider' => 'host-proxy']);
        $sandbox->forceFill(['model_key' => str_repeat('d', 64)])->save();
        Http::fake(['http://10.44.0.20:22000/sessions' => Http::response(['id' => 'sandbox-session'], 201)]);
        $id = app(PiDriver::class)->create(new AgentThreadStart($workspace->node, $workspace, 'Work', 'Implement', $model, 'low', TaskThreadRole::Implementer,
            externalId: 'sandbox-session', deferOpeningTurn: true));

        expect($id)->toBe('sandbox-session');
        Http::assertSent(fn (Request $request): bool => $request['cwd'] === '/home/orbit/orbit'
            && $request['model'] === 'orbit-sandbox/gpt-5.6-luna'
            && $request->hasHeader('Authorization', 'Bearer '.$sandbox->pi_token)
            && ! $request->hasHeader('Authorization', 'Bearer '.$sandbox->model_key));
        Http::assertSentCount(1);
    })->with(['gpt-5.6-luna', 'openai-codex/gpt-5.6-luna']);

    it('preserves shared checkout uniqueness and refuses schema rollback while sandbox workspaces exist', function (): void {
        [$workspace] = sandbox_pi_workspace();
        $shared = $workspace->replicate()->fill(['task_sandbox_id' => null, 'name' => 'shared-one']);
        $shared->save();

        expect(fn () => $shared->replicate()->fill(['name' => 'shared-two'])->save())
            ->toThrow(UniqueConstraintViolationException::class);
        $migration = require database_path('migrations/2026_10_12_000400_scope_instance_checkout_uniqueness_to_shared_workspaces.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove sandbox workspaces');
        $credentials = require database_path('migrations/2026_10_12_000300_add_pi_token_to_task_sandboxes_table.php');
        expect(fn () => $credentials->down())->toThrow(RuntimeException::class, 'Destroy credentialed sandboxes');
        expect($workspace->fresh()->task_sandbox_id)->toBe($workspace->task_sandbox_id);
    });

    it('refuses changed ownership, missing credentials, and stopped or replaced runtimes before HTTP', function (string $fault): void {
        [$workspace, $sandbox, $thread] = sandbox_pi_workspace();
        match ($fault) {
            'parked' => $sandbox->update(['state' => SandboxState::Stopped]),
            'destroyed' => $sandbox->update(['state' => SandboxState::Destroyed]),
            'stopping' => $sandbox->update(['desired_power' => 'stopped']),
            'missing ownership' => $workspace->update(['task_sandbox_id' => null]),
            'foreign workspace' => $sandbox->group->update(['taskable_id' => null]),
            'foreign project' => $workspace->update(['project_id' => Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git'])->id]),
            'unconfigured host' => config(['compute.incus.hosts' => []]),
            'missing port' => $sandbox->update(['spec' => ['host_id' => $workspace->node_id, 'project' => 'orbit-task-sandboxes']]),
            'host runtime' => $thread->update(['runtime_key' => 'node:'.$workspace->node_id]),
            'old reservation' => $thread->update(['runtime_key' => 'sandbox:'.Str::uuid()]),
            'no token' => $sandbox->forceFill(['pi_token' => null])->save(),
        };

        expect(fn () => app(PiDriver::class)->send($thread->fresh(), 'Work', 'key'))->toThrow(AgentDriverException::class);
        Http::assertNothingSent();
    })->with(['parked', 'destroyed', 'stopping', 'missing ownership', 'foreign workspace', 'foreign project', 'unconfigured host', 'missing port', 'host runtime', 'old reservation', 'no token']);

    it('does not create credentials after destruction starts', function (): void {
        [, $sandbox] = sandbox_pi_workspace();
        $sandbox->forceFill(['pi_token' => null, 'state' => SandboxState::Destroying])->save();

        expect(fn () => app(ReserveSandboxPiTokenAction::class)->execute($sandbox))->toThrow(ComputeException::class);
        expect($sandbox->fresh()->pi_token)->toBeNull();
    });

    it('keeps a sandbox token out of server errors and refuses redirects', function (int $status): void {
        [, $sandbox, $thread] = sandbox_pi_workspace();
        Http::fake(['http://10.44.0.20:22000/sessions/*/interrupt' => Http::response(
            ['error' => ['code' => $sandbox->pi_token, 'message' => $sandbox->pi_token]], $status,
            ['Location' => 'http://other.test/collect'],
        )]);

        expect(fn () => app(PiDriver::class)->interrupt($thread))->toThrow(AgentDriverException::class, 'The sandbox Pi server failed with HTTP '.$status.'.');
        Http::assertSentCount(1);
    })->with([401, 302]);

    it('redacts sandbox Pi and model credentials from snapshots and streamed failures', function (bool $stream): void {
        [, $sandbox, $thread] = sandbox_pi_workspace();
        $sandbox->forceFill(['model_key' => str_repeat('e', 64)])->save();
        $snapshot = ['kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 1, 'session' => ['id' => $thread->external_id],
            'state' => 'failed', 'error' => 'token '.$sandbox->pi_token.' model '.$sandbox->model_key, 'entries' => [
                ['id' => 'e1', 'timestamp' => '2026-10-07T00:00:00Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop',
                    'content' => [['type' => 'text', 'text' => 'token '.$sandbox->pi_token.' model '.$sandbox->model_key]]]],
            ]];
        Http::fake(['http://10.44.0.20:22000/sessions/'.$thread->external_id.($stream ? '/stream' : '') => Http::response($stream ? json_encode($snapshot)."\n" : $snapshot)]);

        $result = $stream ? iterator_to_array(app(PiDriver::class)->events($thread, null), false)[0]->data
            : app(PiDriver::class)->observe($thread)->toArray();

        expect($result['error'])->toBe('token [REDACTED] model [REDACTED]');
        expect($result['entries'][0]['text'])->toBe('token [REDACTED] model [REDACTED]');
    })->with([false, true]);
});
