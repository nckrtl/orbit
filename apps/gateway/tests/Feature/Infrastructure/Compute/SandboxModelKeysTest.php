<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Compute\SandboxModelKeys;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

function model_key_sandbox(): TaskSandbox
{
    $project = Project::query()->first() ?? Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Key proof', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);

    return TaskSandbox::query()->create(['id' => (string) Str::uuid(), 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'ot-proof-'.$group->id, 'state' => 'reserved', 'desired_power' => 'running', 'spec' => []]);
}

/** @return Closure(Request): PromiseInterface */
function model_key_service(array &$keys, bool $anonymous = false): Closure
{
    return function (Request $request) use (&$keys, $anonymous): PromiseInterface {
        expect(parse_url($request->url(), PHP_URL_QUERY))->toBeNull();
        $token = substr($request->header('Authorization')[0] ?? '', 7);
        if (str_ends_with($request->url(), '/v1/models')) {
            return Http::response(['data' => []], $anonymous || ($token !== '' && in_array($token, $keys, true)) ? 200 : 401);
        }
        expect($token)->toBe('management-proof-secret');
        if ($request->method() === 'GET') {
            return Http::response(['api-keys' => $keys]);
        }
        expect($request->method())->toBe('PATCH');
        $index = array_search($request['old'], $keys, true);
        if ($index === false) {
            $keys[] = $request['new'];
        } else {
            $keys[$index] = $request['new'];
        }

        return Http::response(['status' => 'ok']);
    };
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['compute.model_proxy.enabled' => true]);
    app(ProxyCliState::class)->enable(1, 'proof', 'http://127.0.0.1:28317', 'management-proof-secret', 'read', 'control', 8787);
});

it('reserves distinct encrypted keys, reuses retries, and revokes only the selected group', function (): void {
    $keys = ['unrelated-key'];
    Http::fake(['http://127.0.0.1:28317/*' => model_key_service($keys)]);
    $first = model_key_sandbox();
    $second = model_key_sandbox();
    $manager = app(SandboxModelKeys::class);

    $manager->ensure($first);
    $key = $first->model_key;
    $manager->ensure($first);
    $manager->ensure($second);

    expect($first->model_key)->toBe($key)->not->toBe($second->model_key);
    expect($first->getRawOriginal('model_key'))->not->toBe($key);
    expect($first->toArray())->not->toHaveKey('model_key');
    expect($first->model_key_registered_at)->not->toBeNull();
    expect(array_filter($keys))->toHaveCount(4);

    $manager->revoke($first);
    $manager->revoke($first);

    expect($first->model_key)->toBeNull()->and($first->model_key_revoked_at)->not->toBeNull();
    expect($keys)->not->toContain($key)->toContain('unrelated-key', $second->model_key);
    expect(fn () => $manager->ensure($first))->toThrow(ComputeException::class, 'cannot register');
});

it('retains the same encrypted reservation after an uncertain registration and retries it', function (): void {
    $keys = [];
    $available = false;
    $service = model_key_service($keys);
    Http::fake(['http://127.0.0.1:28317/*' => function (Request $request) use (&$available, $service): PromiseInterface {
        return $available ? $service($request) : Http::response(['api-keys' => []], 503);
    }]);
    $sandbox = model_key_sandbox();
    $manager = app(SandboxModelKeys::class);

    expect(fn () => $manager->ensure($sandbox))->toThrow(ComputeException::class, 'retained for retry');
    $sandbox->refresh();
    $key = $sandbox->model_key;
    expect($key)->not->toBeNull()->and($sandbox->model_key_registered_at)->toBeNull();
    $available = true;
    $manager->ensure($sandbox);

    expect($sandbox->model_key)->toBe($key)->and($keys)->toContain($key);
});

it('refuses an endpoint change and preserves the key for recovery', function (): void {
    $keys = [];
    Http::fake(['http://127.0.0.1:28317/*' => model_key_service($keys)]);
    $sandbox = model_key_sandbox();
    $manager = app(SandboxModelKeys::class);
    $manager->ensure($sandbox);
    $key = $sandbox->model_key;
    app(ProxyCliState::class)->enable(1, 'proof', 'http://127.0.0.1:28318', 'other', 'read', 'control', 8787);

    expect(fn () => $manager->revoke($sandbox))->toThrow(ComputeException::class, 'Restore the recorded');
    expect($sandbox->fresh()->model_key)->toBe($key);
});

it('does not confirm keys on a server accepting anonymous model requests', function (): void {
    $keys = [];
    Http::fake(['http://127.0.0.1:28317/*' => model_key_service($keys, anonymous: true)]);
    $sandbox = model_key_sandbox();

    expect(fn () => app(SandboxModelKeys::class)->ensure($sandbox))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->model_key_registered_at)->toBeNull()->and($sandbox->fresh()->model_key)->not->toBeNull();
});

it('keeps registration disabled by default without sending HTTP', function (): void {
    config(['compute.model_proxy.enabled' => false]);
    $sandbox = model_key_sandbox();

    expect(fn () => app(SandboxModelKeys::class)->ensure($sandbox))->toThrow(ComputeException::class, 'not enabled');
    expect($sandbox->fresh()->model_key)->toBeNull();
    Http::assertNothingSent();
});

it('retains credentials after unconfirmed revocation and refuses schema rollback', function (): void {
    $keys = [];
    $available = true;
    $service = model_key_service($keys);
    Http::fake(['http://127.0.0.1:28317/*' => function (Request $request) use (&$available, $service): PromiseInterface {
        return $available ? $service($request) : Http::response([], 503);
    }]);
    $sandbox = model_key_sandbox();
    $manager = app(SandboxModelKeys::class);
    $manager->ensure($sandbox);
    $key = $sandbox->model_key;
    $available = false;

    expect(fn () => $manager->revoke($sandbox))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->model_key)->toBe($key)->and($sandbox->fresh()->model_key_revoked_at)->toBeNull();
    $migration = require database_path('migrations/2026_10_12_000500_add_model_key_to_task_sandboxes_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Revoke sandbox model keys');
    $available = true;
    config(['compute.model_proxy.enabled' => false]);
    $manager->revoke($sandbox);

    expect($sandbox->model_key)->toBeNull();
});

it('does not reserve credentials after destruction was requested', function (): void {
    $sandbox = model_key_sandbox();
    $sandbox->update(['desired_power' => 'destroyed']);

    expect(fn () => app(SandboxModelKeys::class)->ensure($sandbox))->toThrow(ComputeException::class, 'cannot register');
    expect($sandbox->fresh()->model_key)->toBeNull();
    Http::assertNothingSent();
});
