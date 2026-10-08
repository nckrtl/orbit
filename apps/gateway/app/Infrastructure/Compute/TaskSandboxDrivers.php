<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;

/** @phpstan-type IncusHost array{node_id: int, project: string, pool: string, max_vms: int, warm_pairs: int, orbit_images: array<string, string>, orbit_source_template: array<string, string>|null, project_images: array<string, string>, blocked_networks: list<string>, gateway_address: string|null, model_proxy_origin: string|null} */
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

    /** @return list<IncusHost> */
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
            $warm = $host['warm_pairs'] ?? 0;
            if (! is_int($warm) || $warm < 0 || $warm > 2 || $warm * 2 > $host['max_vms']) {
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
                'node_id' => $host['node_id'], 'project' => $host['project'], 'pool' => $host['pool'], 'max_vms' => $host['max_vms'], 'warm_pairs' => $warm,
                'orbit_source_template' => $this->sourceTemplate($host['orbit_source_template'] ?? null),
                'orbit_images' => $orbit, 'project_images' => $this->images($host['project_images'] ?? []), 'blocked_networks' => $blocked, 'gateway_address' => $gateway,
                'model_proxy_origin' => $this->modelProxyOrigin($host['model_proxy_origin'] ?? null),
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

    /** @return array<string, string>|null */
    private function sourceTemplate(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || count($value) !== 4 || ! is_string($value['id'] ?? null)
            || ! Str::isUuid($value['id']) || strtolower($value['id']) !== $value['id']
            || ! is_string($value['repository'] ?? null)
            || preg_match('#\Ahttps://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git\z#D', $value['repository']) !== 1
            || ! is_string($value['commit'] ?? null) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value['commit']) !== 1
            || ! is_string($value['base'] ?? null) || ! GitBranchName::isValid($value['base'])
            || preg_match('#\A[A-Za-z0-9][A-Za-z0-9._/-]{0,199}\z#D', $value['base']) !== 1) {
            throw $this->invalidConfiguration();
        }

        return ['id' => $value['id'], 'repository' => $value['repository'], 'base' => $value['base'], 'commit' => $value['commit']];
    }

    private function modelProxyOrigin(mixed $origin): ?string
    {
        if ($origin === null) {
            return null;
        }
        if (! is_string($origin)) {
            throw $this->invalidConfiguration();
        }
        $parts = parse_url($origin);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_array($parts) || ! is_string($host) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || (! str_starts_with($host, '10.44.') && ! str_starts_with($host, '127.'))
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || ($parts['port'] ?? 8317) < 1) {
            throw $this->invalidConfiguration();
        }

        return rtrim($origin, '/');
    }

    private function invalidConfiguration(): ComputeException
    {
        return new ComputeException('compute.invalid_configuration', 'The local sandbox host configuration is invalid.');
    }
}
