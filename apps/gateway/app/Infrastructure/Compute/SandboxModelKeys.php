<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Tasks\TaskCompute;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Persist intent before external registration and retain it until revocation is confirmed. */
final readonly class SandboxModelKeys
{
    public function __construct(private ProxyCliState $proxy, private SettingRepository $settings, private HttpSandboxModelKeys $http) {}

    public function ensure(TaskSandbox $sandbox): void
    {
        $this->locked(function () use ($sandbox): void {
            if (! config('compute.model_proxy.enabled', false)) {
                throw new ComputeException('compute.model_proxy_disabled', 'Sandbox model credentials are not enabled.');
            }
            [$origin, $management] = $this->configuration();
            DB::transaction(function () use ($sandbox, $origin): void {
                $locked = TaskSandbox::query()->lockForUpdate()->findOrFail($sandbox->id);
                if ($locked->group?->task_compute !== TaskCompute::Vm || $locked->model_key_revoked_at !== null || $locked->desired_power === 'destroyed'
                    || in_array($locked->state, [SandboxState::Destroying, SandboxState::Destroyed], true)
                    || ($locked->model_proxy_origin !== null && $locked->model_proxy_origin !== $origin)
                    || (isset($locked->spec['model_proxy_origin']) && $locked->spec['model_proxy_origin'] !== $origin)) {
                    throw new ComputeException('compute.model_key_unavailable', 'The sandbox cannot register model credentials on this endpoint.');
                }
                if ($locked->model_key === null) {
                    $locked->model_key = bin2hex(random_bytes(32));
                    $locked->model_proxy_origin = $origin;
                    $locked->save();
                }
            });
            $sandbox->refresh();
            $key = $sandbox->model_key;
            assert(is_string($key));
            $this->http->ensure($origin, $management, $this->anchor($origin), $key);
            $sandbox->model_key_registered_at ??= now();
            $sandbox->save();
        });
        $sandbox->refresh();
    }

    public function revoke(TaskSandbox $sandbox): void
    {
        $this->locked(function () use ($sandbox): void {
            $sandbox->refresh();
            if ($sandbox->model_key === null) {
                return;
            }
            [$origin, $management] = $this->configuration();
            if ($sandbox->model_proxy_origin !== $origin) {
                throw new ComputeException('compute.model_proxy_changed', 'Restore the recorded model proxy endpoint before revoking this sandbox key.');
            }
            $this->http->revoke($origin, $management, $this->anchor($origin), $sandbox->model_key);
            $sandbox->model_key = null;
            $sandbox->model_key_revoked_at = now();
            $sandbox->save();
        });
        $sandbox->refresh();
    }

    /** @return array{string, string} */
    private function configuration(): array
    {
        $url = $this->proxy->cliproxyUrl();
        $management = $this->proxy->cliproxyManagementKey();
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array(rtrim($parts['path'] ?? '', '/'), ['', '/v0/management'], true)
            || ! is_string($management) || $management === '') {
            throw new ComputeException('compute.model_proxy_unconfigured', 'The model proxy management endpoint is unavailable.');
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [$origin, $management];
    }

    private function anchor(string $origin): string
    {
        $scope = new SettingScope(SettingScopeType::Gateway);
        $name = 'compute.model_proxy.anchor.'.hash('sha256', $origin);
        $key = $this->settings->get($scope, $name);
        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            $this->settings->put($scope, $name, $key, SettingValueProtection::Secret);
        }

        return $key;
    }

    private function locked(callable $operation): void
    {
        $lock = Cache::lock('orbit:compute:model-keys', 600);
        if (! $lock->get()) {
            throw new ComputeException('compute.busy', 'Another sandbox model-key operation is running.');
        }
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }
}
