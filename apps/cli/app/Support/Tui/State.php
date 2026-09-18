<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Realtime\RealtimeEvent;
use App\Support\Tui\Sources\DatabaseUsersSource;
use App\Support\Tui\Sources\DeploymentsSource;
use App\Support\Tui\Sources\NodeMetricsSource;
use Closure;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Processes\AppInstanceProcessTarget;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

/**
 * The fleet-wide data `orbit top` renders, loaded once with a handful of SDK requests and kept
 * current afterwards by realtime events (see RealtimeSubscriber) or, when realtime is not
 * configured, by reloading on a fixed tick. Every record is a plain array using the same field
 * names the family's list/show commands render, so the rendering code in Screen never touches
 * an SDK response DTO directly.
 */
final class State
{
    /** @var list<array<string, mixed>> */
    public array $nodes = [];

    /** @var list<array<string, mixed>> */
    public array $apps = [];

    /** @var list<array<string, mixed>> */
    public array $instances = [];

    /** @var list<array<string, mixed>> */
    public array $processes = [];

    /** @var list<array<string, mixed>> */
    public array $schedules = [];

    /** @var list<array<string, mixed>> */
    public array $firewall = [];

    /** @var list<array<string, mixed>> */
    public array $databases = [];

    /** Lazily loaded per record id: database slug => tables, process id => log lines, schedule id => log lines. */
    /** @var array<string, list<string>> */
    public array $databaseTables = [];

    /** @var array<int, list<string>> */
    public array $processLogs = [];

    /** @var array<string, list<string>> */
    public array $scheduleLogs = [];

    /**
     * Metrics carried live by `node.sample` realtime events, keyed by node id. Checked before
     * falling back to NodeMetricsSource, so a Gateway that streams samples but does not yet
     * answer `GET /nodes/{node}/metrics` still shows live numbers.
     *
     * @var array<int, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}>
     */
    public array $nodeSamples = [];

    public string $liveness = 'polling';

    public function __construct(
        private readonly DeploymentsSource $deployments,
        private readonly DatabaseUsersSource $databaseUsers,
        private readonly NodeMetricsSource $nodeMetrics,
    ) {}

    /**
     * Loads every list once, using $send to perform each typed SDK request. $send has the same
     * shape as GatewayCommand::sendOrThrow(): it throws GatewayApiException on failure.
     *
     * @param  Closure(object, string): object  $send
     */
    public function load(Closure $send): void
    {
        $nodes = $send(new ListNodesRequest, NodesResponse::class);
        $apps = $send(new ListAppsRequest, AppsResponse::class);
        $instances = $send(new ListAppInstancesRequest, AppInstancesResponse::class);
        assert($nodes instanceof NodesResponse);
        assert($apps instanceof AppsResponse);
        assert($instances instanceof AppInstancesResponse);

        $this->nodes = array_map(self::nodeRow(...), $nodes->nodes);
        $this->apps = array_map(self::appRow(...), $apps->apps);
        $this->instances = array_map(self::instanceRow(...), $instances->appInstances);

        $processes = [];

        foreach ($this->nodes as $node) {
            $response = $send(new ListProcessesRequest(new NodeProcessTarget($node['id'])), ProcessesResponse::class);
            assert($response instanceof ProcessesResponse);
            array_push($processes, ...array_map(self::processRow(...), $response->processes));
        }

        foreach ($this->instances as $instance) {
            $response = $send(new ListProcessesRequest(new AppInstanceProcessTarget($instance['id'])), ProcessesResponse::class);
            assert($response instanceof ProcessesResponse);
            array_push($processes, ...array_map(self::processRow(...), $response->processes));
        }

        $this->processes = $processes;

        $schedules = $send(new ListSchedulesRequest, SchedulesResponse::class);
        assert($schedules instanceof SchedulesResponse);
        $this->schedules = array_map(self::scheduleRow(...), $schedules->schedules);

        $firewall = [];

        foreach ($this->nodes as $node) {
            $response = $send(new ListFirewallRulesRequest($node['id']), FirewallRulesResponse::class);
            assert($response instanceof FirewallRulesResponse);
            array_push($firewall, ...array_map(self::firewallRow(...), $response->rules));
        }

        $this->firewall = $firewall;

        $databases = $send(new ListDatabaseConnectionsRequest, DatabaseConnectionsResponse::class);
        assert($databases instanceof DatabaseConnectionsResponse);
        $this->databases = array_map(self::databaseRow(...), $databases->connections);
    }

    /** Applies one realtime event to the in-memory collections it names. */
    public function applyEvent(RealtimeEvent $event): void
    {
        [$family, $verb] = array_pad(explode('.', $event->type, 2), 2, null);

        match (true) {
            $family === 'node' && $verb === 'sample' => $this->applyNodeSample($event->data),
            $family === 'node' => $this->applyTo('nodes', 'id', $verb, $event->data),
            $family === 'app' => $this->applyTo('apps', 'id', $verb, $event->data),
            $family === 'instance' => $this->applyTo('instances', 'id', $verb, $event->data),
            $family === 'process' => $this->applyTo('processes', 'id', $verb, $event->data),
            $family === 'schedule' => $this->applyTo('schedules', 'id', $verb, $event->data),
            $family === 'database' => $this->applyTo('databases', 'id', $verb, $event->data),
            $family === 'firewall' => $this->applyTo('firewall', 'id', $verb, $event->data),
            // deploy_step and deployment change AppInstance-embedded data or history this
            // Gateway does not expose to the CLI yet (see Sources\DeploymentsSource); a full
            // reload picks up the new deploy steps. route events do not change any pane top
            // draws today.
            default => null,
        };
    }

    /**
     * Merges a record actions menu's refreshed row (a plain array in this row's own shape,
     * with its id) into the matching family collection, the same way an "updated" realtime
     * event would. Used because an action's response arrives synchronously, ahead of the
     * event that will eventually confirm it.
     *
     * @param  array<string, mixed>  $row
     */
    public function updateRow(string $kind, array $row): void
    {
        $collection = match ($kind) {
            'nodes' => 'nodes',
            'apps' => 'apps',
            'instances' => 'instances',
            'processes' => 'processes',
            'schedules' => 'schedules',
            'databases' => 'databases',
            'firewall' => 'firewall',
            default => null,
        };

        if ($collection !== null) {
            $this->applyTo($collection, 'id', 'updated', $row);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyNodeSample(array $data): void
    {
        $nodeId = $data['node_id'] ?? $data['id'] ?? null;
        $cores = $data['cores'] ?? null;
        $mem = $data['mem'] ?? null;
        $swap = $data['swap'] ?? null;
        $disks = $data['disks'] ?? null;

        if (! is_int($nodeId) || ! is_array($cores) || ! is_array($mem) || ! is_array($swap) || ! is_array($disks)) {
            return;
        }

        $this->nodeSamples[$nodeId] = [
            'cores' => array_map(floatval(...), $cores),
            'mem' => [(float) ($mem[0] ?? 0), (float) ($mem[1] ?? 0)],
            'swap' => [(float) ($swap[0] ?? 0), (float) ($swap[1] ?? 0)],
            'uptime' => is_string($data['uptime'] ?? null) ? $data['uptime'] : '—',
            'disks' => array_map(static fn (array $disk): array => [(string) ($disk[0] ?? '/'), (float) ($disk[1] ?? 0), (float) ($disk[2] ?? 0)], $disks),
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyTo(string $collection, string $key, ?string $verb, array $data): void
    {
        $id = $data[$key] ?? null;

        if ($id === null) {
            return;
        }

        $rows = $this->{$collection};

        if ($verb === 'deleted') {
            $this->{$collection} = array_values(array_filter($rows, static fn (array $row): bool => $row[$key] !== $id));

            return;
        }

        $found = false;

        foreach ($rows as $index => $row) {
            if ($row[$key] === $id) {
                $rows[$index] = [...$row, ...$data];
                $found = true;
            }
        }

        if (! $found && in_array($verb, ['created', 'updated', 'status'], true)) {
            $rows[] = $data;
        }

        $this->{$collection} = $rows;
    }

    /** @return array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null */
    public function nodeMetrics(int $nodeId): ?array
    {
        return $this->nodeSamples[$nodeId] ?? $this->nodeMetrics->forNode($nodeId);
    }

    /** @return list<array<string, mixed>>|null */
    public function deploymentsFor(int $instanceId): ?array
    {
        return $this->deployments->forInstance($instanceId);
    }

    /** @return list<array<string, mixed>>|null */
    public function databaseUsersFor(string $slug): ?array
    {
        return $this->databaseUsers->forConnection($slug);
    }

    public function instanceName(int $id): string
    {
        foreach ($this->instances as $instance) {
            if ($instance['id'] === $id) {
                return "{$instance['app']['slug']}/{$instance['name']}";
            }
        }

        return '—';
    }

    public function instanceNodeName(int $id): string
    {
        foreach ($this->instances as $instance) {
            if ($instance['id'] === $id) {
                return $instance['node']['name'];
            }
        }

        return '—';
    }

    /** @param array<string, mixed> $process */
    public function processOwner(array $process): string
    {
        return $process['target_type'] === 'node' ? "node {$this->nodeName($process['target_id'])}" : $this->instanceName($process['target_id']);
    }

    /** @param array<string, mixed> $process */
    public function processNodeName(array $process): string
    {
        return $process['target_type'] === 'node' ? $this->nodeName($process['target_id']) : $this->instanceNodeName($process['target_id']);
    }

    public function nodeName(int $id): string
    {
        foreach ($this->nodes as $node) {
            if ($node['id'] === $id) {
                return $node['name'];
            }
        }

        return '—';
    }

    /** @return array<string, mixed>|null */
    public function nodeByName(string $name): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['name'] === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function appBySlug(string $slug): ?array
    {
        foreach ($this->apps as $app) {
            if ($app['slug'] === $slug) {
                return $app;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function instancesForNode(string $nodeName): array
    {
        return array_values(array_filter($this->instances, static fn (array $i): bool => $i['node']['name'] === $nodeName));
    }

    /** @return list<array<string, mixed>> */
    public function instancesForApp(string $appSlug): array
    {
        return array_values(array_filter($this->instances, static fn (array $i): bool => $i['app']['slug'] === $appSlug));
    }

    /** @return list<array<string, mixed>> */
    public function processesForInstance(int $instanceId): array
    {
        return array_values(array_filter($this->processes, static fn (array $p): bool => $p['target_type'] === 'instance' && $p['target_id'] === $instanceId));
    }

    /** @return list<array<string, mixed>> */
    public function processesForNode(int $nodeId): array
    {
        return array_values(array_filter($this->processes, static fn (array $p): bool => $p['target_type'] === 'node' && $p['target_id'] === $nodeId));
    }

    /** @return list<array<string, mixed>> */
    public function schedulesForInstance(int $instanceId): array
    {
        return array_values(array_filter($this->schedules, static fn (array $s): bool => $s['target_type'] === 'instance' && $s['target_id'] === $instanceId));
    }

    /** @return list<array<string, mixed>> */
    public function schedulesForApp(string $appSlug): array
    {
        $ids = array_column($this->instancesForApp($appSlug), 'id');

        return array_values(array_filter($this->schedules, static fn (array $s): bool => in_array($s['target_id'], $ids, true)));
    }

    /** @return list<array<string, mixed>> */
    public function firewallForNode(int $nodeId): array
    {
        return array_values(array_filter($this->firewall, static fn (array $f): bool => $f['node_id'] === $nodeId));
    }

    /**
     * The section's list, narrowed by the node and app filters where the section admits them.
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(string $section, ?string $nodeFilter, ?string $appFilter): array
    {
        $instanceIds = array_column(array_filter(
            $this->instances,
            static fn (array $i): bool => ($nodeFilter === null || $i['node']['name'] === $nodeFilter) && ($appFilter === null || $i['app']['slug'] === $appFilter),
        ), 'id');

        return match ($section) {
            'nodes' => $this->nodes,
            'apps' => $this->apps,
            'instances' => array_values(array_filter($this->instances, static fn (array $i): bool => in_array($i['id'], $instanceIds, true))),
            'processes' => array_values(array_filter($this->processes, fn (array $p): bool => $p['target_type'] === 'node'
                ? $appFilter === null && ($nodeFilter === null || $this->nodeName($p['target_id']) === $nodeFilter)
                : in_array($p['target_id'], $instanceIds, true))),
            'schedules' => array_values(array_filter($this->schedules, static fn (array $s): bool => $s['target_type'] === 'instance' && in_array($s['target_id'], $instanceIds, true))),
            'databases' => array_values(array_filter($this->databases, fn (array $d): bool => $nodeFilter === null || $this->nodeName((int) $d['node_id']) === $nodeFilter)),
            default => [],
        };
    }

    /**
     * Everything that is yellow somewhere, gathered for the dashboard's "Needs attention" list.
     *
     * @return list<array{kind: string, record: array<string, mixed>, label: string, name: string, where: string, state: string}>
     */
    public function attentionRows(): array
    {
        $rows = [];

        foreach ($this->nodes as $node) {
            if ($node['status'] !== 'active') {
                $rows[] = ['kind' => 'nodes', 'record' => $node, 'label' => 'Node', 'name' => $node['name'], 'where' => '—', 'state' => $node['status']];
            }
        }

        foreach ($this->instances as $instance) {
            if ($instance['status'] !== 'active') {
                $rows[] = ['kind' => 'instances', 'record' => $instance, 'label' => 'Instance', 'name' => "{$instance['app']['slug']}/{$instance['name']}", 'where' => $instance['node']['name'], 'state' => $instance['status']];
            }
        }

        foreach ($this->processes as $process) {
            if ($process['runtime_status'] !== $process['desired_state']) {
                $rows[] = ['kind' => 'processes', 'record' => $process, 'label' => 'Process', 'name' => $process['name'], 'where' => $this->processOwner($process), 'state' => "{$process['runtime_status']}, wanted {$process['desired_state']}"];
            }
        }

        foreach ($this->schedules as $schedule) {
            if ($schedule['desired_timer_state'] !== 'enabled' || $schedule['status'] === 'failed') {
                $rows[] = ['kind' => 'schedules', 'record' => $schedule, 'label' => 'Schedule', 'name' => $schedule['name'], 'where' => $this->instanceName($schedule['target_id']), 'state' => $schedule['status'] === 'failed' ? 'failed' : $schedule['desired_timer_state']];
            }
        }

        foreach ($this->firewall as $rule) {
            if ($rule['status'] !== 'applied') {
                $rows[] = ['kind' => 'firewall', 'record' => $rule, 'label' => 'Firewall', 'name' => "{$rule['port']}/{$rule['protocol']} {$rule['action']} {$rule['source']}", 'where' => $rule['node'], 'state' => $rule['status']];
            }
        }

        return $rows;
    }

    /** @return array{Nodes: array{int,int}, Apps: array{int,int}, Instances: array{int,int}, Processes: array{int,int}, Schedules: array{int,int}, Firewall: array{int,int}} */
    public function counts(): array
    {
        $off = static fn (array $rows, callable $ok): int => count(array_filter($rows, static fn (array $row): bool => ! $ok($row)));

        return [
            'Nodes' => [count($this->nodes), $off($this->nodes, static fn (array $n): bool => $n['status'] === 'active')],
            'Apps' => [count($this->apps), 0],
            'Instances' => [count($this->instances), $off($this->instances, static fn (array $i): bool => $i['status'] === 'active')],
            'Processes' => [count($this->processes), $off($this->processes, static fn (array $p): bool => $p['runtime_status'] === $p['desired_state'])],
            'Schedules' => [count($this->schedules), $off($this->schedules, static fn (array $s): bool => $s['desired_timer_state'] === 'enabled')],
            'Firewall' => [count($this->firewall), $off($this->firewall, static fn (array $f): bool => $f['status'] === 'applied')],
        ];
    }

    /**
     * @param  object{id: int, name: string, status: string, roles: list<string>, platform: ?string, architecture: ?string, tld: ?string, wireguardIp: ?string, publicSshHost: string, publicSshPort: int, user: string}  $node
     * @return array<string, mixed>
     */
    public static function nodeRow(object $node): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'status' => $node->status,
            'roles' => $node->roles,
            'platform' => $node->platform,
            'architecture' => $node->architecture,
            'tld' => $node->tld,
            'wireguard_ip' => $node->wireguardIp,
            'public_ssh_host' => $node->publicSshHost,
            'public_ssh_port' => $node->publicSshPort,
            'user' => $node->user,
        ];
    }

    /**
     * @param  object{id: int, name: string, slug: string, defaultBranch: ?string, root: ?string, repositoryUrl: string}  $app
     * @return array<string, mixed>
     */
    public static function appRow(object $app): array
    {
        return [
            'id' => $app->id,
            'name' => $app->name,
            'slug' => $app->slug,
            'default_branch' => $app->defaultBranch,
            'root' => $app->root,
            'repository_url' => $app->repositoryUrl,
        ];
    }

    /** @return array<string, mixed> */
    public static function instanceRow(object $instance): array
    {
        return [
            'id' => $instance->id,
            'app' => $instance->app?->toArray() ?? ['id' => $instance->appId, 'name' => '—', 'slug' => '—'],
            'node' => $instance->node?->toArray() ?? ['id' => $instance->nodeId, 'name' => '—'],
            'name' => $instance->name,
            'environment' => $instance->environment,
            'domain' => $instance->domain,
            'status' => $instance->status,
            'checkout_path' => $instance->checkoutPath,
            'selected_branch' => $instance->selectedBranch,
            'deploy_steps' => array_map(static fn (object $step): array => $step->toArray(), $instance->deploySteps),
        ];
    }

    /** @return array<string, mixed> */
    public static function processRow(object $process): array
    {
        return [
            'id' => $process->id,
            'target_type' => $process->targetType,
            'target_id' => $process->targetId,
            'name' => $process->name,
            'runtime' => $process->runtime,
            'working_directory' => $process->workingDirectory,
            'restart_policy' => $process->restartPolicy,
            'desired_state' => $process->desiredState,
            'status' => $process->status,
            'runtime_status' => $process->runtimeStatus,
            'failed_step' => $process->failedStep,
        ];
    }

    /** @return array<string, mixed> */
    public static function scheduleRow(object $schedule): array
    {
        return [
            'id' => $schedule->id,
            'target_type' => $schedule->targetType,
            'target_id' => $schedule->targetId,
            'name' => $schedule->name,
            'calendar' => $schedule->calendar,
            'command' => $schedule->command,
            'timeout_seconds' => $schedule->timeoutSeconds,
            'desired_timer_state' => $schedule->desiredTimerState,
            'status' => $schedule->status,
            'failed_step' => $schedule->failedStep,
            'last_run_at' => $schedule->lastRunAt,
            'last_run_status' => $schedule->lastRunStatus,
        ];
    }

    /** @return array<string, mixed> */
    public static function firewallRow(object $rule): array
    {
        return [
            'id' => $rule->id,
            'node_id' => $rule->nodeId,
            'node' => $rule->node,
            'name' => $rule->name,
            'action' => $rule->action,
            'source' => $rule->source,
            'protocol' => $rule->protocol,
            'port' => $rule->port,
            'status' => $rule->status,
            'backend_status' => $rule->backendStatus,
            'failed_step' => $rule->failedStep,
        ];
    }

    /** @return array<string, mixed> */
    public static function databaseRow(object $connection): array
    {
        return [
            'id' => $connection->id,
            'slug' => $connection->slug,
            'driver' => $connection->driver,
            'node_id' => $connection->nodeId,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'path' => $connection->path,
            'username' => $connection->username,
            'has_password' => $connection->hasPassword,
        ];
    }
}
