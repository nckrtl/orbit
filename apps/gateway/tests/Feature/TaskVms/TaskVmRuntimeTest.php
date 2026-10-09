<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\ProcessesApiFakeRuntimeManager;

use function Pest\Laravel\mock;

const TVM_RUNTIME_ORIGIN = 'http://10.44.0.3:8317';
const TVM_RUNTIME_PI_TOKEN = 'task-vm-pi-token-with-more-than-32-characters';

function tvm_runtime_settings(?string $artifact, ?string $digest, array $models = ['gpt-5.6-luna']): void
{
    app()->instance(TaskVmSettings::class, new TaskVmSettings(
        enabled: true, devClusterId: 4, wireguardRange: '10.44.0.128/25', hosts: [], modelProxyOrigin: TVM_RUNTIME_ORIGIN,
        piArtifactPath: $artifact, piArtifactSha256: $digest, piModels: $models,
    ));
}

function tvm_runtime_vm(bool $enrolled = true): TaskVm
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = static fn (string $name, string $address, string $user): Node => Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user,
    ]);
    $host = $node('beast', '10.44.0.7', 'nckrtl');
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);

    return TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $enrolled ? $node('tvm-1', '10.44.0.130', 'orbit')->id : null,
        'provider' => 'incus', 'name' => 'tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.130', 'pi_token' => TVM_RUNTIME_PI_TOKEN,
    ]);
}

/**
 * A CLIProxyAPI that keeps its key list in memory, as its management API does.
 *
 * @return ArrayObject<int, string>
 */
function tvm_runtime_cliproxy(): ArrayObject
{
    $keys = new ArrayObject;
    Http::fake([TVM_RUNTIME_ORIGIN.'/*' => function (Request $request) use ($keys) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v0/management/api-keys' && $request->method() === 'GET') {
            return Http::response(['api-keys' => array_values($keys->getArrayCopy())]);
        }
        if ($path === '/v0/management/api-keys' && $request->method() === 'PATCH') {
            $index = array_search($request['old'], $keys->getArrayCopy(), true);
            if ($index !== false) {
                unset($keys[$index]);
            }
            if ($request['new'] !== '') {
                $keys->append($request['new']);
            }
            $keys->exchangeArray(array_values($keys->getArrayCopy()));

            return Http::response(['status' => 'ok']);
        }
        $token = substr($request->header('Authorization')[0] ?? '', 7);

        return Http::response([], in_array($token, $keys->getArrayCopy(), true) ? 200 : 401);
    }]);

    return $keys;
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $this->transport = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $this->transport);
    $this->processes = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->processes);
    app(ProxyCliState::class)->enable(1, 'cache', TVM_RUNTIME_ORIGIN, 'management-key', 'read-token', 'control-token');
    $this->artifact = tempnam(sys_get_temp_dir(), 'pi-artifact');
    file_put_contents($this->artifact, 'pi-server executable');
    tvm_runtime_settings($this->artifact, hash_file('sha256', $this->artifact));
});

afterEach(function (): void {
    @unlink($this->artifact);
});

describe('prepare', function (): void {
    it('registers the group key, installs Pi as the managed user and starts the pi-server Process', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();

        app(TaskVmRuntime::class)->prepare($vm);

        $key = $vm->fresh()?->model_key;
        expect($key)->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($keys->getArrayCopy())->toHaveCount(2)->toContain($key);

        [$install, $models, $token] = $this->transport->commands;
        expect(array_map(static fn ($connection): array => [$connection->host, $connection->user], $this->transport->connections))
            ->toBe(array_fill(0, 3, ['10.44.0.130', 'orbit']))
            ->and([$install->arguments[0], $install->arguments[1], ...array_slice($install->arguments, 3)])
            ->toBe(['bash', '-ceu', '--', hash_file('sha256', $this->artifact)])
            ->and($install->arguments[2])->toContain('sha256sum --check --status', 'mv -f -- "$staged" "$bin/pi-server"')
            ->and(stream_get_contents($install->protectedInput?->stream()))->toBe('pi-server executable')
            ->and(array_slice($models->arguments, 3))->toBe(['--', 'models.json'])
            ->and($models->arguments[2])->toContain('install -d -m 0700 -- "$HOME/.pi" "$dir"')
            ->and(json_decode((string) stream_get_contents($models->protectedInput?->stream()), true))->toBe(['providers' => ['orbit-task-vm' => [
                'baseUrl' => TVM_RUNTIME_ORIGIN.'/v1', 'api' => 'openai-responses', 'apiKey' => $key,
                'models' => [['id' => 'gpt-5.6-luna', 'reasoning' => true]],
            ]]])
            ->and(array_slice($token->arguments, 3))->toBe(['--', 'orbit-token'])
            ->and(stream_get_contents($token->protectedInput?->stream()))->toBe(TVM_RUNTIME_PI_TOKEN)
            ->and(implode(' ', array_map(static fn ($command): string => implode(' ', $command->arguments), $this->transport->commands)))
            ->not->toContain($key, TVM_RUNTIME_PI_TOKEN);

        $process = Process::query()->sole();
        expect($process->owner_id)->toBe($vm->node_id)
            ->and($process->name)->toBe('pi-server')
            ->and($process->runtime)->toBe(ProcessRuntime::Systemd)
            ->and($process->runtime_config['command'])->toBe([
                '/home/orbit/.local/bin/pi-server', 'serve', '--host=10.44.0.130', '--port=3774',
                '--token-file=/home/orbit/.pi/agent/orbit-token', '--allow-provider=orbit-task-vm',
            ])
            ->and($process->runtime_config)->not->toHaveKey('user')
            ->and($process->restart_policy)->toBe('always')
            ->and($process->keep_alive)->toBeTrue()
            ->and($process->status)->toBe(LifecycleStatus::Active);
    });

    it('keeps the key and the Process when it runs again', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();

        app(TaskVmRuntime::class)->prepare($vm);
        $key = $vm->fresh()?->model_key;
        app(TaskVmRuntime::class)->prepare($vm->fresh());

        expect($vm->fresh()?->model_key)->toBe($key)
            ->and($keys->getArrayCopy())->toHaveCount(2)
            ->and(Process::query()->count())->toBe(1)
            ->and($this->transport->commands)->toHaveCount(6);
    });

    it('refuses before any change without a Node or the Pi settings', function (string $missing, string $code): void {
        Http::fake();
        $vm = tvm_runtime_vm($missing !== 'node');
        if ($missing === 'artifact') {
            tvm_runtime_settings(null, null);
        } elseif ($missing === 'models') {
            tvm_runtime_settings($this->artifact, hash_file('sha256', $this->artifact), []);
        }

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe($code));
        expect($this->transport->commands)->toBe([])->and($vm->fresh()?->model_key)->toBeNull();
        Http::assertNothingSent();
    })->with([
        ['node', 'task_vm.not_enrolled'],
        ['artifact', 'task_vm.invalid_config'],
        ['models', 'task_vm.invalid_config'],
    ]);

    it('names the task VM when a step fails on it', function (): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $this->transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'sha256sum: WARNING: 1 computed checksum did NOT match', 1, false)]);
        app()->instance(SshExecutor::class, $this->transport);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(TaskVmException::class, 'Pi could not be prepared on task VM [tvm-1].');
        expect($this->transport->commands)->toHaveCount(1)->and(Process::query()->count())->toBe(0);
    });

    it('fails with a model key error when CLIProxyAPI refuses the key', function (): void {
        Http::fake([TVM_RUNTIME_ORIGIN.'/*' => Http::response([], 403)]);
        $vm = tvm_runtime_vm();

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.model_key_failed'));
        expect($this->transport->commands)->toBe([])
            ->and($vm->fresh()?->model_key)->toMatch('/\A[a-f0-9]{64}\z/');
    });
});

describe('release', function (): void {
    it('revokes the group key and keeps the anchor', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        app(TaskVmRuntime::class)->prepare($vm);
        $key = $vm->fresh()?->model_key;

        app(TaskVmRuntime::class)->release($vm->fresh());

        expect($vm->fresh()?->model_key)->toBeNull()
            ->and($keys->getArrayCopy())->toHaveCount(1)->not->toContain($key);
    });

    it('does nothing without a key', function (): void {
        Http::fake();

        app(TaskVmRuntime::class)->release(tvm_runtime_vm());

        Http::assertNothingSent();
    });
});
