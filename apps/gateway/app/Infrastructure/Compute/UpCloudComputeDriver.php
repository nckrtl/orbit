<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;

final readonly class UpCloudComputeDriver implements ComputeDriver
{
    public function __construct(private UpCloudClient $client, private UpCloudCloudInit $bootstrap, private ComputeLocks $locks) {}

    public function capacity(): int
    {
        if (! config('compute.upcloud.enabled', false)) {
            return 0;
        }
        $budget = config('compute.upcloud.max_vms', 0);
        if (! is_int($budget) || $budget < 0) {
            throw new ComputeException('compute.invalid_budget', 'The UpCloud VM budget is invalid.');
        }

        return max(0, $budget - TaskSandbox::query()->where('provider', 'upcloud')->where('state', '!=', SandboxState::Destroyed->value)->count());
    }

    public function provision(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, function (TaskSandbox $sandbox): TaskSandbox {
            if (! config('compute.upcloud.enabled', false)) {
                throw new ComputeException('compute.disabled', 'UpCloud compute is disabled.');
            }
            if ($sandbox->desired_power === 'destroyed' || $sandbox->state === SandboxState::Destroyed) {
                throw new ComputeException('compute.ended', 'This sandbox is being destroyed or has already been destroyed.');
            }
            if ($sandbox->create_attempted_at === null) {
                $spec = SandboxSpec::fromArray($sandbox->spec);
                $body = $this->createRequest($sandbox, $spec);
                $budget = config('compute.upcloud.max_vms', 0);
                if (! is_int($budget) || $budget < 1 || TaskSandbox::query()->where('provider', 'upcloud')->where('state', '!=', SandboxState::Destroyed->value)->count() > $budget) {
                    throw new ComputeException('compute.capacity', 'The UpCloud VM budget is full.');
                }
                $sandbox->update(['state' => SandboxState::Creating, 'create_attempted_at' => now(), 'credential_fingerprint' => $this->client->credentialFingerprint()]);
                $result = $this->request($sandbox, 'POST', 'server', $body);
                $createdId = data_get($result, 'server.uuid');
                if (is_string($createdId) && Str::isUuid($createdId)) {
                    $sandbox->update(['server_id' => $createdId]);
                }
            }

            return $this->observeOwned($sandbox);
        });
    }

    public function observe(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, fn (TaskSandbox $sandbox): TaskSandbox => $sandbox->desired_power === 'destroyed'
            ? $this->destroyOwned($sandbox)
            : $this->observeOwned($sandbox));
    }

    /** The enrollment caller must verify cloud-init completion before sealing the network. */
    public function sealNetwork(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, function (TaskSandbox $sandbox): TaskSandbox {
            if ($sandbox->desired_power === 'destroyed' || $sandbox->state === SandboxState::Destroyed) {
                throw new ComputeException('compute.ended', 'This sandbox is being destroyed or has already been destroyed.');
            }
            $sandbox->update(['network_policy' => 'sealed', 'firewall_configured_at' => null]);

            return $this->observeOwned($sandbox);
        });
    }

    public function park(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, fn (TaskSandbox $sandbox): TaskSandbox => $this->power($sandbox, 'stopped'));
    }

    public function resume(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, fn (TaskSandbox $sandbox): TaskSandbox => $this->power($sandbox, 'running'));
    }

    public function destroy(TaskSandbox $sandbox): TaskSandbox
    {
        return $this->operate($sandbox, function (TaskSandbox $sandbox): TaskSandbox {
            if ($sandbox->node_id !== null) {
                throw new ComputeException('compute.node_attached', 'Remove the sandbox Node from the fleet before destroying its VM.');
            }
            $sandbox->update(['desired_power' => 'destroyed']);

            return $this->destroyOwned($sandbox);
        });
    }

    /** @param callable(TaskSandbox): TaskSandbox $operation */
    private function operate(TaskSandbox $sandbox, callable $operation): TaskSandbox
    {
        return $this->locks->upcloud(function () use ($sandbox, $operation): TaskSandbox {
            if (! $sandbox->exists || ! Str::isUuid($sandbox->id)) {
                throw $this->ownership();
            }
            $sandbox->refresh();
            if ($sandbox->provider !== 'upcloud' || $sandbox->name !== 'orbit-sandbox-'.$sandbox->id) {
                throw $this->ownership();
            }
            foreach ([$sandbox->server_id, $sandbox->disk_id] as $id) {
                if ($id !== null && ! Str::isUuid($id)) {
                    throw $this->ownership();
                }
            }
            try {
                if ($sandbox->create_attempted_at !== null && $sandbox->state !== SandboxState::Destroyed
                    && (! is_string($sandbox->credential_fingerprint) || ! hash_equals($sandbox->credential_fingerprint, $this->client->credentialFingerprint()))) {
                    throw new ComputeException('compute.credential_changed', 'The UpCloud credential changed; confirm provider ownership before recovery.');
                }
                $result = $operation($sandbox);
                $result->update(['error_code' => null]);

                return $result->refresh();
            } catch (ComputeException $exception) {
                $attributes = ['error_code' => $exception->errorCode];
                if (in_array($exception->errorCode, ['compute.provider_unavailable', 'compute.provider_failed', 'compute.provider_invalid_response', 'compute.firewall_failed', 'compute.ownership_mismatch', 'compute.provider_not_ready'], true)) {
                    $attributes['state'] = $sandbox->desired_power === 'destroyed' ? SandboxState::Destroying : SandboxState::Uncertain;
                }
                $sandbox->update($attributes);

                throw $exception;
            }
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function request(TaskSandbox $sandbox, string $method, string $path, array $data = [], bool $allowMissing = false): ?array
    {
        if (! is_string($sandbox->credential_fingerprint)) {
            throw $this->ownership();
        }

        return $this->client->request($method, $path, $sandbox->credential_fingerprint, $data, $allowMissing);
    }

    /** @return array<string, mixed> */
    private function createRequest(TaskSandbox $sandbox, SandboxSpec $spec): array
    {
        return ['server' => [
            'hostname' => $sandbox->name, 'title' => $sandbox->name, 'zone' => $spec->zone,
            'plan' => $spec->plan(), 'metadata' => 'yes', 'firewall' => 'on', 'remote_access_enabled' => 'no',
            'labels' => ['label' => [['key' => 'orbit-sandbox', 'value' => $sandbox->id]]],
            'networking' => ['interfaces' => ['interface' => [[
                'type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]],
            ]]]],
            'storage_devices' => ['storage_device' => [[
                'action' => 'clone', 'storage' => $spec->image, 'size' => $spec->diskGb(), 'tier' => 'standard', 'title' => $sandbox->name.'-disk',
            ]]],
            'user_data' => $this->bootstrap->render($spec),
        ]];
    }

    private function observeOwned(TaskSandbox $sandbox): TaskSandbox
    {
        if ($sandbox->state === SandboxState::Destroyed) {
            return $sandbox;
        }
        $server = $this->server($sandbox);
        if ($server === null) {
            if ($sandbox->create_attempted_at === null) {
                return $sandbox;
            }
            throw $this->uncertain($sandbox);
        }
        $state = $server['state'] ?? null;
        if ($state === 'started') {
            if (($server['firewall'] ?? null) !== 'on') {
                throw new ComputeException('compute.firewall_failed', 'The UpCloud sandbox firewall is not enabled.');
            }
            $this->ensureFirewall($sandbox);
            $sandbox->update(['state' => SandboxState::Running]);
        } elseif ($state === 'stopped') {
            $sandbox->update(['state' => SandboxState::Stopped]);
        } elseif (in_array($state, ['maintenance', 'error'], true)) {
            throw new ComputeException('compute.provider_not_ready', 'The UpCloud VM needs provider recovery.');
        } elseif (! in_array($state, ['started', 'stopped', 'starting', 'stopping', 'creating', 'deleting'], true)) {
            throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid VM state.');
        } else {
            $sandbox->update(['state' => match ($state) {
                'starting' => SandboxState::Starting,
                'stopping' => SandboxState::Stopping,
                'deleting' => SandboxState::Destroying,
                default => SandboxState::Creating,
            }]);
        }

        return $sandbox;
    }

    private function power(TaskSandbox $sandbox, string $desired): TaskSandbox
    {
        if ($sandbox->state === SandboxState::Destroyed || $sandbox->desired_power === 'destroyed') {
            throw new ComputeException('compute.ended', 'This sandbox is being destroyed or has already been destroyed.');
        }
        $sandbox->update(['desired_power' => $desired]);
        $server = $this->server($sandbox);
        if ($server === null) {
            throw $this->uncertain($sandbox);
        }
        if ($desired === 'stopped' && ($server['state'] ?? null) === 'started') {
            $this->request($sandbox, 'POST', 'server/'.$sandbox->server_id.'/stop', ['stop_server' => ['stop_type' => 'soft', 'timeout' => '30']]);
            $sandbox->update(['state' => SandboxState::Stopping]);

            return $sandbox;
        }
        if ($desired === 'running' && ($server['state'] ?? null) === 'stopped') {
            $this->ensureFirewall($sandbox);
            $this->request($sandbox, 'POST', 'server/'.$sandbox->server_id.'/start', ['server' => new \stdClass]);
            $sandbox->update(['state' => SandboxState::Starting]);

            return $sandbox;
        }

        return $this->observeOwned($sandbox);
    }

    private function destroyOwned(TaskSandbox $sandbox): TaskSandbox
    {
        if ($sandbox->model_key !== null) {
            throw new ComputeException('compute.model_key_attached', 'Revoke the sandbox model key before destroying its compute.');
        }
        if ($sandbox->state === SandboxState::Destroyed) {
            return $sandbox;
        }
        if ($sandbox->node_id !== null) {
            throw new ComputeException('compute.node_attached', 'Remove the sandbox Node from the fleet before destroying its VM.');
        }
        $sandbox->update(['state' => SandboxState::Destroying]);
        if ($sandbox->create_attempted_at === null) {
            return $this->destroyed($sandbox);
        }
        $server = $this->server($sandbox);
        if ($server === null && $sandbox->server_id === null) {
            throw $this->uncertain($sandbox);
        }
        if ($server !== null) {
            $state = $server['state'] ?? null;
            if ($state === 'started') {
                $this->request($sandbox, 'POST', 'server/'.$sandbox->server_id.'/stop', ['stop_server' => ['stop_type' => 'hard']]);

                return $sandbox;
            }
            if ($state !== 'stopped') {
                return $sandbox;
            }
            $this->request($sandbox, 'DELETE', 'server/'.$sandbox->server_id.'/?storages=1');

            return $sandbox;
        }
        if ($sandbox->disk_id === null) {
            throw $this->ownership();
        }
        $disk = $this->request($sandbox, 'GET', 'storage/'.$sandbox->disk_id, allowMissing: true);
        if ($disk === null) {
            return $this->destroyed($sandbox);
        }
        $storage = $disk['storage'] ?? null;
        if (! is_array($storage) || ($storage['uuid'] ?? null) !== $sandbox->disk_id
            || ($storage['title'] ?? null) !== $sandbox->name.'-disk'
            || data_get($storage, 'servers.server') !== []) {
            throw $this->ownership();
        }
        $this->request($sandbox, 'DELETE', 'storage/'.$sandbox->disk_id);

        return $sandbox;
    }

    /** @return array<string, mixed>|null */
    private function server(TaskSandbox $sandbox): ?array
    {
        if ($sandbox->create_attempted_at === null) {
            return null;
        }
        $id = $sandbox->server_id;
        if ($id === null) {
            $servers = data_get($this->request($sandbox, 'GET', 'server'), 'servers.server');
            if (! is_array($servers) || count($servers) > 5000) {
                throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid VM list.');
            }
            $matches = array_values(array_filter($servers, fn (mixed $server): bool => is_array($server)
                && ($server['hostname'] ?? null) === $sandbox->name && ($server['title'] ?? null) === $sandbox->name));
            if ($matches === []) {
                return null;
            }
            if (count($matches) !== 1 || ! is_string($matches[0]['uuid'] ?? null) || ! Str::isUuid($matches[0]['uuid'])) {
                throw $this->ownership();
            }
            $id = $matches[0]['uuid'];
        }
        $result = $this->request($sandbox, 'GET', 'server/'.$id, allowMissing: true);
        if ($result === null) {
            return null;
        }
        $server = $result['server'] ?? null;
        $labels = is_array($server) ? data_get($server, 'labels.label') : null;
        if (! is_array($server) || ($server['uuid'] ?? null) !== $id
            || ($server['hostname'] ?? null) !== $sandbox->name || ($server['title'] ?? null) !== $sandbox->name
            || ! is_array($labels)
            || ! in_array(['key' => 'orbit-sandbox', 'value' => $sandbox->id], $labels, true)) {
            throw $this->ownership();
        }
        $devices = data_get($server, 'storage_devices.storage_device');
        if (! is_array($devices) || count($devices) !== 1 || ! is_array($devices[0] ?? null) || ! is_string($devices[0]['storage'] ?? null)
            || ! Str::isUuid($devices[0]['storage']) || ($devices[0]['storage_title'] ?? null) !== $sandbox->name.'-disk'
            || ($devices[0]['type'] ?? null) !== 'disk'
            || ($sandbox->disk_id !== null && $sandbox->disk_id !== $devices[0]['storage'])) {
            throw $this->ownership();
        }
        $sandbox->update(['server_id' => $id, 'disk_id' => $devices[0]['storage']]);
        $addresses = data_get($server, 'ip_addresses.ip_address', []);
        foreach (is_array($addresses) ? $addresses : [] as $address) {
            if (is_array($address) && ($address['access'] ?? null) === 'public' && ($address['family'] ?? null) === 'IPv4'
                && is_string($address['address'] ?? null) && filter_var($address['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $sandbox->update(['public_address' => $address['address']]);
            }
        }

        return $server;
    }

    private function ensureFirewall(TaskSandbox $sandbox): void
    {
        $expected = $this->bootstrap->firewall(SandboxSpec::fromArray($sandbox->spec), $sandbox->network_policy === 'sealed');
        $path = 'server/'.$sandbox->server_id.'/firewall_rule';
        $rules = data_get($this->request($sandbox, 'GET', $path), 'firewall_rules.firewall_rule');
        if (! $this->sameFirewall($rules, $expected)) {
            $this->request($sandbox, 'PUT', $path, ['firewall_rules' => ['firewall_rule' => $expected]]);
            $rules = data_get($this->request($sandbox, 'GET', $path), 'firewall_rules.firewall_rule');
            if (! $this->sameFirewall($rules, $expected)) {
                throw new ComputeException('compute.firewall_failed', 'The UpCloud sandbox firewall did not converge.');
            }
        }
        $sandbox->update(['firewall_configured_at' => now()]);
    }

    /**
     * @param  list<array<string, string>>  $expected
     */
    private function sameFirewall(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $rule) {
            if (! is_array($actual[$index] ?? null)) {
                return false;
            }
            $normalized = array_filter($actual[$index], fn (mixed $value, string $key): bool => ! in_array($key, ['position', 'comment'], true) && $value !== '', ARRAY_FILTER_USE_BOTH);
            ksort($normalized);
            ksort($rule);
            if ($normalized !== $rule) {
                return false;
            }
        }

        return true;
    }

    private function destroyed(TaskSandbox $sandbox): TaskSandbox
    {
        $sandbox->update(['state' => SandboxState::Destroyed, 'destroyed_at' => now()]);

        return $sandbox;
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The UpCloud resource does not match the recorded sandbox ownership.');
    }

    private function uncertain(TaskSandbox $sandbox): ComputeException
    {
        $sandbox->update(['state' => SandboxState::Uncertain]);

        return new ComputeException('compute.creation_uncertain', 'The sandbox creation is unresolved; no second VM will be created.');
    }
}
