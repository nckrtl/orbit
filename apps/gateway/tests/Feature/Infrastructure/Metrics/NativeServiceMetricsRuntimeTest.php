<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
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
use App\Models\Cluster;
use App\Models\Node;
use Tests\Support\FakeNodeCaddyBuilds;

beforeEach(function (): void {
    $this->ssh = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(0, '{}', '', 1, false);
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

/** @param list<RemoteCommand> $commands */
function service_metrics_runtime_text(array $commands): string
{
    return implode("\n", array_map(
        static fn (RemoteCommand $command): string => implode(' ', $command->arguments)."\n".($command->input ?? ''),
        $commands,
    ));
}
