<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Processes\AddProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\HttpSandboxModelKeys;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;
use Throwable;

/**
 * Prepares Pi on an enrolled task VM over the Node's normal SSH connection, as its managed user:
 * the group's CLIProxyAPI key, the pinned Pi executable, `models.json`, the token, and the normal
 * `pi-server` Process. Every step is idempotent, so a retried job repeats it. `release` revokes the key.
 */
final readonly class TaskVmRuntime
{
    /** The only `models.json` provider on a task VM. CLIProxyAPI is the VM's only model route. */
    public const string PiProvider = 'orbit-task-vm';

    private const int ArtifactMaxBytes = 268435456;

    /** Shared with the Orbit lane, so one anchor key guards each CLIProxyAPI. */
    private const string ModelKeyLock = 'orbit:compute:model-keys';

    private const string InstallPi = <<<'BASH'
        bin="$HOME/.local/bin"
        install -d -m 0755 -- "$HOME/.local" "$bin"
        staged=$(mktemp "$bin/.pi-server.XXXXXX")
        trap 'rm -f -- "$staged"' EXIT
        cat >"$staged"
        printf '%s  %s\n' "$1" "$staged" | sha256sum --check --status
        chmod 0755 -- "$staged"
        mv -f -- "$staged" "$bin/pi-server"
        trap - EXIT
        BASH;

    private const string WritePrivate = <<<'BASH'
        dir="$HOME/.pi/agent"
        install -d -m 0700 -- "$HOME/.pi" "$dir"
        staged=$(mktemp "$dir/.$1.XXXXXX")
        trap 'rm -f -- "$staged"' EXIT
        cat >"$staged"
        mv -f -- "$staged" "$dir/$1"
        trap - EXIT
        BASH;

    public function __construct(
        private TaskVmSettings $settings,
        private DevelopmentSshExecutor $ssh,
        private HttpSandboxModelKeys $keys,
        private ProxyCliState $proxy,
        private SettingRepository $gatewaySettings,
        private AddProcessAction $processes,
        private PiConnection $pi,
    ) {}

    public function prepare(TaskVm $vm): void
    {
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no Node yet.");
        $origin = $this->origin();
        if (rtrim((string) $this->proxy->cliproxyUrl(), '/') !== $origin) {
            throw $this->invalidConfig('model_proxy_origin must be the CLIProxyAPI URL of the proxycli extension.');
        }
        if ($vm->model_proxy_origin !== null && $vm->model_proxy_origin !== $origin) {
            throw $this->invalidConfig("model_proxy_origin changed while task VM [{$vm->name}] holds a key at [{$vm->model_proxy_origin}].");
        }
        $artifact = $this->settings->piArtifactPath;
        $digest = $this->settings->piArtifactSha256;
        if ($artifact === null || $digest === null || $this->settings->piModels === []) {
            throw $this->invalidConfig('pi.artifact_path, pi.artifact_sha256 and pi.models are required to prepare a task VM.');
        }
        $key = $this->ensureModelKey($vm, $origin);

        try {
            $home = '/home/'.$node->user;
            $this->run($node, ['bash', '-ceu', self::InstallPi, '--', $digest], ProtectedInput::fromFile($artifact, '', self::ArtifactMaxBytes), 'task-vm-pi-artifact', 600);
            $this->run($node, ['bash', '-ceu', self::WritePrivate, '--', 'models.json'], ProtectedInput::fromString($this->models($origin, $key)), 'task-vm-pi-models', 60);
            $this->run($node, ['bash', '-ceu', self::WritePrivate, '--', 'orbit-token'], ProtectedInput::fromString($vm->pi_token), 'task-vm-pi-token', 60);
            $this->processes->execute(new AddProcessData(
                targetType: ProcessTargetType::Node,
                targetId: $node->id,
                name: 'pi-server',
                runtime: ProcessRuntime::Systemd,
                command: [
                    $home.'/.local/bin/pi-server', 'serve', '--host='.$node->wireguard_ip, '--port='.$this->pi->port(),
                    '--token-file='.$home.'/.pi/agent/orbit-token', '--allow-provider='.self::PiProvider,
                ],
                image: null,
                workingDirectory: null,
                environment: [],
                ports: [],
                volumes: [],
                restartPolicy: 'always',
                start: true,
                keepAlive: true,
            ));
        } catch (Throwable $exception) {
            throw new TaskVmException('task_vm.runtime_failed', "Pi could not be prepared on task VM [{$vm->name}].", 502, $exception);
        }
    }

    /** Revokes the group's model key at the origin that holds it. A task VM without a key has nothing to revoke. */
    public function release(TaskVm $vm): void
    {
        if ($vm->model_key === null) {
            return;
        }
        $origin = $vm->model_proxy_origin ?? $this->origin();
        $this->withModelKeyLock(function () use ($vm, $origin): void {
            $vm->refresh();
            if ($vm->model_key !== null) {
                $this->keys->revoke($origin, $this->managementKey(), $this->anchor($origin), $vm->model_key);
                $vm->update(['model_key' => null]);
            }
        });
    }

    /** The key and its origin are stored before CLIProxyAPI learns the key, so a retry registers the same key there. */
    private function ensureModelKey(TaskVm $vm, string $origin): string
    {
        return $this->withModelKeyLock(function () use ($vm, $origin): string {
            $vm->refresh();
            if ($vm->model_key === null) {
                $vm->update(['model_key' => bin2hex(random_bytes(32)), 'model_proxy_origin' => $origin]);
            }
            $key = (string) $vm->model_key;
            $this->keys->ensure($origin, $this->managementKey(), $this->anchor($origin), $key);

            return $key;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withModelKeyLock(callable $operation): mixed
    {
        try {
            return Cache::lock(self::ModelKeyLock, 600)->block(60, $operation);
        } catch (TaskVmException $exception) {
            throw $exception;
        } catch (LockTimeoutException $exception) {
            throw new TaskVmException('task_vm.model_key_failed', 'Another model key operation is running.', 409, $exception);
        } catch (Throwable $exception) {
            throw new TaskVmException('task_vm.model_key_failed', 'The group model key could not be confirmed with CLIProxyAPI.', 502, $exception);
        }
    }

    private function models(string $origin, #[SensitiveParameter] string $key): string
    {
        return json_encode(['providers' => [self::PiProvider => [
            'baseUrl' => $origin.'/v1',
            'api' => 'openai-responses',
            'apiKey' => $key,
            'models' => array_map(static fn (string $model): array => ['id' => $model, 'reasoning' => true], $this->settings->piModels),
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /** @param list<string> $arguments */
    private function run(Node $node, array $arguments, ProtectedInput $input, string $step, float $timeout): void
    {
        $this->ssh->execute($node, new RemoteCommand($arguments, protectedInput: $input, timeout: $timeout), $step, 'task_vm.runtime_failed', $timeout, 'Task VM runtime');
    }

    private function origin(): string
    {
        return $this->settings->modelProxyOrigin ?? throw $this->invalidConfig('model_proxy_origin is required to prepare a task VM.');
    }

    private function managementKey(): string
    {
        $key = $this->proxy->cliproxyManagementKey();
        if ($key === null || $key === '') {
            throw new TaskVmException('task_vm.model_key_failed', 'Set up the proxycli extension: it holds the CLIProxyAPI management key.', 409);
        }

        return $key;
    }

    /** One random anchor key per origin keeps CLIProxyAPI's key list from ever becoming empty, which would open it. */
    private function anchor(string $origin): string
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $name = 'compute.model_proxy.anchor.'.hash('sha256', $origin);
        $key = $this->gatewaySettings->get($scope, $name);
        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            $this->gatewaySettings->put($scope, $name, $key, SettingValueProtection::Secret);
        }

        return $key;
    }

    private function invalidConfig(string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_config', "The task VM config is invalid: {$reason}", 500);
    }
}
