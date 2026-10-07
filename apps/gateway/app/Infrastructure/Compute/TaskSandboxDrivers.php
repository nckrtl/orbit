<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\TaskSandbox;

final readonly class TaskSandboxDrivers
{
    public function __construct(private UpCloudComputeDriver $upcloud, private IncusSandboxHost $transport, private ComputeLocks $locks) {}

    public function forSandbox(TaskSandbox $sandbox): ComputeDriver
    {
        if ($sandbox->provider === 'upcloud') {
            return $this->upcloud;
        }
        if ($sandbox->provider !== 'incus') {
            throw new ComputeException('compute.unknown_provider', 'The sandbox provider is unavailable.');
        }
        $hostId = $sandbox->spec['host_id'] ?? null;
        foreach ($this->localHosts() as $settings) {
            if ($hostId === $settings['node_id']) {
                return $this->local($settings);
            }
        }

        throw new ComputeException('compute.host_unconfigured', 'Restore the recorded Incus host configuration before recovering this sandbox.');
    }

    /** @return list<array{node_id: int, project: string, pool: string, max_vms: int, orbit_images: array<string, string>, project_images: array<string, string>, blocked_networks: list<string>, gateway_address: string|null}> */
    public function localHosts(): array
    {
        $value = config('compute.incus.hosts', []);
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalidConfiguration();
        }
        $result = [];
        $ids = [];
        foreach ($value as $host) {
            if (! is_array($host) || ! is_int($host['node_id'] ?? null) || $host['node_id'] < 1
                || in_array($host['node_id'], $ids, true) || ! is_int($host['max_vms'] ?? null)
                || $host['max_vms'] < 1 || $host['max_vms'] > 64
                || ! is_string($host['pool'] ?? null) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}\z/D', $host['pool']) !== 1
                || ! is_string($host['project'] ?? null) || preg_match('/\Aorbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)\z/D', $host['project']) !== 1
                || ! is_array($host['blocked_networks'] ?? null) || ! array_is_list($host['blocked_networks']) || $host['blocked_networks'] === []) {
                throw $this->invalidConfiguration();
            }
            $gateway = $host['gateway_address'] ?? null;
            if ($gateway !== null && (! is_string($gateway) || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($gateway, '10.44.'))) {
                throw $this->invalidConfiguration();
            }
            $blocked = [];
            foreach ($host['blocked_networks'] as $network) {
                if (! is_string($network) || preg_match('/\A[0-9.]+\/[0-9]{1,2}\z/D', $network) !== 1) {
                    throw $this->invalidConfiguration();
                }
                $blocked[] = $network;
            }
            $orbit = $this->images($host['orbit_images'] ?? []);
            if (array_diff(array_keys($orbit), ['operator', 'gateway', 'app-dev', 'app-prod', 'app-prod-2']) !== []) {
                throw $this->invalidConfiguration();
            }
            $ids[] = $host['node_id'];
            $result[] = [
                'node_id' => $host['node_id'], 'project' => $host['project'], 'pool' => $host['pool'], 'max_vms' => $host['max_vms'],
                'orbit_images' => $orbit, 'project_images' => $this->images($host['project_images'] ?? []), 'blocked_networks' => $blocked, 'gateway_address' => $gateway,
            ];
        }

        return $result;
    }

    /** @param array{node_id: int, project: string, max_vms: int} $settings */
    public function local(array $settings): IncusComputeDriver
    {
        $node = Node::query()->find($settings['node_id']);
        if (! $node instanceof Node) {
            throw new ComputeException('compute.host_missing', 'The recorded Incus host is no longer enrolled.');
        }

        return new IncusComputeDriver($node, $settings['project'], $settings['max_vms'], $this->transport, $this->locks);
    }

    /** @return array<string, string> */
    private function images(mixed $value): array
    {
        if (! is_array($value)) {
            throw $this->invalidConfiguration();
        }
        $images = [];
        foreach ($value as $role => $fingerprint) {
            if (! is_string($role) || preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D', $role) !== 1
                || ! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw $this->invalidConfiguration();
            }
            $images[$role] = $fingerprint;
        }

        return $images;
    }

    private function invalidConfiguration(): ComputeException
    {
        return new ComputeException('compute.invalid_configuration', 'The local sandbox host configuration is invalid.');
    }
}
