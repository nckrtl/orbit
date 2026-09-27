<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Metrics\NativeServiceMetricsRuntime;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use Tests\Support\FakeNodeCaddyBuilds;

beforeEach(function (): void {
    $this->ssh = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        /** @var list<string> */
        public array $outputs = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(0, array_shift($this->outputs) ?? '{}', '', 1, false);
        }
    };
    $this->builds = new FakeNodeCaddyBuilds;
    $this->runtime = new NativeServiceMetricsRuntime(
        new AppProdSshExecutor(
            $this->ssh,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/orbit-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 AAAA test';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/orbit-test-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ),
        $this->builds,
    );
    $this->metrics = service_metrics_runtime_node('metrics', '10.44.0.7', []);
});

it('requests a Node Caddy build of an Ingress Node and writes no Caddy file', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress, RoleName::AppProd]);

    $this->runtime->converge(new ServiceMetricsNode($ingress, true, false), $this->metrics);

    expect($this->builds->built)->toBe(['app-prod'])
        ->and(service_metrics_runtime_text($this->ssh->commands))
        ->not->toContain('orbit-versions', '00-metrics-service.caddy', '/etc/caddy/Caddyfile');
});

it('builds no Node that cannot render the scrape site', function (): void {
    $workload = service_metrics_runtime_node('app-dev', '10.44.0.3', [RoleName::AppDev]);

    $this->runtime->converge(new ServiceMetricsNode($workload, false, false), $this->metrics);

    expect($this->builds->built)->toBe([]);
});

it('snapshots only exporter and pool state and restores by building the Node', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $target = new ServiceMetricsNode($ingress, true, false);

    $snapshot = $this->runtime->snapshot($target);
    $commandsBeforeRestore = count($this->ssh->commands);
    $this->runtime->restore($target, $snapshot);

    expect(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR))->toBe(['exporter' => [], 'pools' => []])
        ->and(service_metrics_runtime_text($this->ssh->commands))->not->toContain('/etc/caddy')
        ->and(count($this->ssh->commands))->toBe($commandsBeforeRestore + 1)
        ->and($this->builds->built)->toBe(['app-prod']);
});

it('rejects scalar snapshots and snapshots without valid pool or exporter state', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $instance = service_metrics_runtime_instance($ingress);
    $target = new ServiceMetricsNode($ingress, true, true, [$instance]);

    expect(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), '42'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore($target, json_encode(['exporter' => [], 'pools' => []], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore($target, json_encode(['exporter' => [], 'pools' => 'invalid'], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), json_encode(['pools' => []], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'))
        ->and(fn () => $this->runtime->restore(new ServiceMetricsNode($ingress, true, false), json_encode(['pools' => [], 'exporter' => null], JSON_THROW_ON_ERROR)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'));
});

it('rejects scalar FPM pool snapshots and restores valid exporter and instance pool state', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $instance = service_metrics_runtime_instance($ingress);
    $target = new ServiceMetricsNode($ingress, true, true, [$instance]);

    $this->ssh->outputs = ['{}', '42'];
    expect(fn () => $this->runtime->snapshot($target))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_inspection_failed'));

    $this->ssh->outputs = ['{"enabled":true}', '{"fingerprint":"pool-123"}'];
    $snapshot = $this->runtime->snapshot($target);
    $state = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
    $commandsBeforeRestore = count($this->ssh->commands);

    $this->ssh->outputs = ['{}', '{}'];
    $this->runtime->restore($target, $snapshot);

    expect($state['exporter'])->toBe(['enabled' => true])
        ->and($state['pools'][(string) $instance->id])->toBe(['fingerprint' => 'pool-123'])
        ->and(count($this->ssh->commands))->toBe($commandsBeforeRestore + 2)
        ->and(service_metrics_runtime_request($this->ssh->commands[$commandsBeforeRestore]))
        ->toMatchArray(['operation' => 'restore', 'state' => ['fingerprint' => 'pool-123']])
        ->and(service_metrics_runtime_request($this->ssh->commands[$commandsBeforeRestore + 1]))
        ->toMatchArray(['operation' => 'apply', 'state' => ['enabled' => true]])
        ->and($this->builds->built)->toBe(['app-prod']);
});

it('keeps its error code and names the build stage when the build fails', function (): void {
    $ingress = service_metrics_runtime_node('app-prod', '10.44.0.4', [RoleName::Ingress]);
    $this->builds->failNext('app-prod', 'addresses', 'The build binds 192.168.6.30, which is not an address on this Node.');

    expect(fn () => $this->runtime->converge(new ServiceMetricsNode($ingress, true, false), $this->metrics))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('metrics.caddy_publication_failed')
                ->and($exception->step)->toBe('service-metrics-caddy')
                ->and($exception->getMessage())->toContain('failed at stage [addresses]');
        });
});

/** @param list<RoleName> $roles */
function service_metrics_runtime_node(string $name, string $address, array $roles): Node
{
    $cluster = in_array(RoleName::Ingress, $roles, true)
        ? Cluster::query()->create(['name' => "{$name}-cluster", 'state' => ClusterState::Active])
        : null;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'user' => 'orbit',
        'wireguard_ip' => $address,
        'cluster_id' => $cluster?->id,
    ]);

    foreach ($roles as $role) {
        $node->roles()->create([
            'role' => $role,
            'status' => LifecycleStatus::Active,
            'cluster_id' => $role === RoleName::Ingress ? $cluster?->id : null,
        ]);
    }

    return $node;
}

function service_metrics_runtime_instance(Node $node): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'Metrics fixture',
        'slug' => 'metrics-fixture',
        'repository_url' => 'https://example.test/metrics-fixture.git',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/metricapp/current',
        'root' => 'public',
        'production_user' => 'metricapp',
        'production_home' => '/home/metricapp',
        'selected_php_version' => '8.5',
        'production_php_service' => 'orbit-metricapp-php8.5-fpm.service',
        'production_php_pool' => 'orbit-metricapp',
        'production_php_socket' => '/run/php/metricapp.sock',
        'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function service_metrics_runtime_request(RemoteCommand $command): array
{
    $encoded = $command->arguments[3] ?? null;
    if (! is_string($encoded)) {
        return [];
    }

    $request = json_decode(base64_decode($encoded, true) ?: '', true);

    return is_array($request) ? array_filter($request, is_string(...), ARRAY_FILTER_USE_KEY) : [];
}

/** @param list<RemoteCommand> $commands */
function service_metrics_runtime_text(array $commands): string
{
    return implode("\n", array_map(
        static fn (RemoteCommand $command): string => implode(' ', $command->arguments)."\n".($command->input ?? ''),
        $commands,
    ));
}
