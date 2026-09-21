<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Realtime\RealtimeEvent;
use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
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
    use LimitsBackgroundRequestTime;

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

    /** False until every pending Process request has answered; the screen says so rather than "none". */
    public bool $processesLoaded = false;

    /** @var list<GatewayRequest> Process requests not sent yet, drained a few per frame. */
    private array $pendingProcessRequests = [];

    /** @var list<array<string, mixed>> */
    public array $databases = [];

    /** Lazily loaded per record id: database slug => tables, process id => log lines, schedule id => log lines, deployment id => event log lines. */
    /** @var array<string, list<string>> */
    public array $databaseTables = [];

    /** @var array<int, list<string>> */
    public array $processLogs = [];

    /** @var array<string, list<string>> */
    public array $scheduleLogs = [];

    /** @var array<int, list<string>> */
    public array $deploymentLogs = [];

    /**
     * Metrics carried live by `node.sample` realtime events, keyed by node id. Checked before
     * falling back to NodeMetricsSource, so a Gateway that streams samples but does not answer
     * `GET /nodes/{node}/metrics` still shows live numbers.
     *
     * @var array<int, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}>
     */
    public array $nodeSamples = [];

    public string $liveness = 'polling';

    /**
     * Node metrics fetched by RefreshScheduler, keyed by node id. Screen only ever reads this
     * cache; it never triggers the Gateway request itself (see RefreshScheduler's class doc for
     * why: the request that fills it can take seconds, and Screen draws every frame).
     *
     * @var array<int, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null>
     */
    private array $nodeMetricsCache = [];

    /** @var array<int, list<array<string, mixed>>|null> */
    private array $deploymentsCache = [];

    /** @var array<string, list<array<string, mixed>>|null> */
    private array $databaseUsersCache = [];

    /**
     * Loads every list once, using $send to perform each typed SDK request. $send has the same
     * shape as GatewayCommand::sendOrThrow(): it throws GatewayApiException on failure.
     *
     * This is only what the dashboard's first frame draws: every fleet-wide list, plus firewall
     * rules, which are scoped per Node and so need one cheap request each. Processes are not
     * here — see loadProcesses(), which the command runs after the first frame because it is
     * slow enough to be the whole of a startup wait. $sendMany, when given, runs the per-Node
     * firewall batch concurrently through GatewayCommand::poolSend() instead of one at a time;
     * omitting it (as the Tui unit tests do) falls back to calling $send once per request, in
     * the same order, so callers that only need correctness need not provide it.
     *
     * @param  Closure(object, string): object  $send
     * @param  null|Closure(list<GatewayRequest>, string): list<object>  $sendMany
     */
    public function load(Closure $send, ?Closure $sendMany = null): void
    {
        $sendMany ??= static fn (array $requests, string $responseClass): array => array_map(
            static fn (GatewayRequest $request): object => $send($request, $responseClass),
            $requests,
        );

        $nodes = $send(new ListNodesRequest, NodesResponse::class);
        $apps = $send(new ListAppsRequest, AppsResponse::class);
        $instances = $send(new ListAppInstancesRequest, AppInstancesResponse::class);
        assert($nodes instanceof NodesResponse);
        assert($apps instanceof AppsResponse);
        assert($instances instanceof AppInstancesResponse);

        $this->nodes = array_map(self::nodeRow(...), $nodes->nodes);
        $this->apps = array_map(self::appRow(...), $apps->apps);
        $this->instances = array_map(self::instanceRow(...), $instances->appInstances);

        $schedules = $send(new ListSchedulesRequest, SchedulesResponse::class);
        assert($schedules instanceof SchedulesResponse);
        $this->schedules = array_map(self::scheduleRow(...), $schedules->schedules);

        $firewallRequests = array_map(static fn (array $node): GatewayRequest => new ListFirewallRulesRequest($node['id']), $this->nodes);
        $firewall = [];

        foreach ($sendMany($firewallRequests, FirewallRulesResponse::class) as $response) {
            assert($response instanceof FirewallRulesResponse);
            array_push($firewall, ...array_map(self::firewallRow(...), $response->rules));
        }

        $this->firewall = $firewall;

        $databases = $send(new ListDatabaseConnectionsRequest, DatabaseConnectionsResponse::class);
        assert($databases instanceof DatabaseConnectionsResponse);
        $this->databases = array_map(self::databaseRow(...), $databases->connections);
    }

    /**
     * Queues every Process request the fleet needs, for `loadNextProcesses()` to drain.
     *
     * `GET /api/v1/processes` without a target answers with the whole fleet, so this is one
     * request. It stays out of `load()` regardless: nothing the first frame draws depends on it,
     * and the queue keeps the screen responsive if a fleet ever makes it slow again.
     */
    public function queueProcesses(): void
    {
        // One request for the fleet. The Gateway reads every Process from its own table and
        // resolves their live state in a single Prometheus query, so asking per Node and per
        // AppInstance would be dozens of round trips for one table.
        $this->pendingProcessRequests = [new ListProcessesRequest];
        $this->processes = [];
        $this->processesLoaded = false;
    }

    /**
     * Sends the next few queued Process requests and merges what comes back.
     *
     * The command calls this once per idle frame rather than draining the queue in one call, so
     * a fleet whose Process status checks take seconds still redraws and answers a key press
     * between batches, and "Needs attention" fills in as the answers arrive. The batch is small
     * and each request is capped on purpose: a key pressed while one is in flight waits for it,
     * and the Gateway reads every owned Process's live state over SSH, which on a real fleet
     * reached 3.5 seconds for a single instance.
     *
     * @param  Closure(object, string): object  $send
     * @param  null|Closure(list<GatewayRequest>, string): list<object>  $sendMany
     * @return bool Whether anything was sent; false once the queue is empty.
     */
    public function loadNextProcesses(Closure $send, ?Closure $sendMany = null, int $batch = 3): bool
    {
        if ($this->pendingProcessRequests === []) {
            return false;
        }

        $sendMany ??= static fn (array $requests, string $responseClass): array => array_map(
            static fn (GatewayRequest $request): object => $send($request, $responseClass),
            $requests,
        );

        $requests = array_map(
            self::withBackgroundTimeout(...),
            array_splice($this->pendingProcessRequests, 0, max(1, $batch)),
        );

        foreach ($sendMany($requests, ProcessesResponse::class) as $response) {
            assert($response instanceof ProcessesResponse);
            array_push($this->processes, ...array_map(self::processRow(...), $response->processes));
        }

        $this->processesLoaded = $this->pendingProcessRequests === [];

        return true;
    }

    /**
     * Every Process in the fleet, in one call. The render loop drains the queue a batch at a
     * time instead (see loadNextProcesses); this is for the polling fallback and for a test that
     * wants a finished State.
     *
     * @param  Closure(object, string): object  $send
     * @param  null|Closure(list<GatewayRequest>, string): list<object>  $sendMany
     */
    public function loadProcesses(Closure $send, ?Closure $sendMany = null): void
    {
        $this->queueProcesses();

        while ($this->loadNextProcesses($send, $sendMany, PHP_INT_MAX)) {
            // Drains in one batch.
        }

        $this->processesLoaded = true;
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
            // deploy_step events change AppInstance-embedded deploy steps; a full reload
            // (State::load()) picks those up. deployment events need no handling here:
            // deploymentsFor() re-polls Sources\DeploymentsSource on its own short interval, so
            // an open Deployments pane picks up new history on its own. route events do not
            // change any pane top draws today.
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

    /**
     * The last node metrics RefreshScheduler fetched for this node (or a live `node.sample`
     * event, if one arrived), or null when none has landed yet. A pure cache read: Screen calls
     * this on every drawn frame and must never trigger the Gateway request that fills it.
     *
     * @return array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null
     */
    public function nodeMetrics(int $nodeId): ?array
    {
        return $this->nodeSamples[$nodeId] ?? $this->nodeMetricsCache[$nodeId] ?? null;
    }

    /**
     * RefreshScheduler calls this after it fetches (or fails to fetch) one node's metrics.
     *
     * @param  array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null  $metrics
     */
    public function setNodeMetrics(int $nodeId, ?array $metrics): void
    {
        $this->nodeMetricsCache[$nodeId] = $metrics;
    }

    /**
     * The last deployment history RefreshScheduler fetched for this AppInstance, or null when
     * none has landed yet. A pure cache read; see nodeMetrics().
     *
     * @return list<array<string, mixed>>|null
     */
    public function deploymentsFor(int $instanceId): ?array
    {
        return $this->deploymentsCache[$instanceId] ?? null;
    }

    /**
     * RefreshScheduler calls this after it fetches (or fails to fetch) one instance's deployments.
     *
     * @param  list<array<string, mixed>>|null  $deployments
     */
    public function setDeployments(int $instanceId, ?array $deployments): void
    {
        $this->deploymentsCache[$instanceId] = $deployments;
    }

    /**
     * The last Database connection users RefreshScheduler fetched for this connection, or null
     * when none has landed yet. A pure cache read; see nodeMetrics().
     *
     * @return list<array<string, mixed>>|null
     */
    public function databaseUsersFor(string $slug): ?array
    {
        return $this->databaseUsersCache[$slug] ?? null;
    }

    /**
     * RefreshScheduler calls this after it fetches (or fails to fetch) one connection's users.
     *
     * @param  list<array<string, mixed>>|null  $users
     */
    public function setDatabaseUsers(string $slug, ?array $users): void
    {
        $this->databaseUsersCache[$slug] = $users;
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
            'firewall' => array_values(array_filter($this->firewall, static fn (array $f): bool => $nodeFilter === null || $f['node'] === $nodeFilter)),
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
            if (! self::nodeHealthy($node)) {
                $rows[] = ['kind' => 'nodes', 'record' => $node, 'label' => 'Node', 'name' => $node['name'], 'where' => '—', 'state' => $node['status']];
            }
        }

        foreach ($this->instances as $instance) {
            if (! self::instanceHealthy($instance)) {
                $rows[] = ['kind' => 'instances', 'record' => $instance, 'label' => 'Instance', 'name' => "{$instance['app']['slug']}/{$instance['name']}", 'where' => $instance['node']['name'], 'state' => $instance['status']];
            }
        }

        foreach ($this->processes as $process) {
            if (! self::processHealthy($process)) {
                $rows[] = ['kind' => 'processes', 'record' => $process, 'label' => 'Process', 'name' => $process['name'], 'where' => $this->processOwner($process), 'state' => "{$process['runtime_status']}, wanted {$process['desired_state']}"];
            }
        }

        foreach ($this->schedules as $schedule) {
            if (! self::scheduleHealthy($schedule)) {
                $rows[] = ['kind' => 'schedules', 'record' => $schedule, 'label' => 'Schedule', 'name' => $schedule['name'], 'where' => $this->instanceName($schedule['target_id']), 'state' => $schedule['status'] === 'failed' ? 'failed' : $schedule['desired_timer_state']];
            }
        }

        foreach ($this->firewall as $rule) {
            if (! self::firewallHealthy($rule)) {
                $rows[] = ['kind' => 'firewall', 'record' => $rule, 'label' => 'Firewall', 'name' => "{$rule['port']}/{$rule['protocol']} {$rule['action']} {$rule['source']}", 'where' => $rule['node'], 'state' => $rule['status']];
            }
        }

        return $rows;
    }

    /** @return array{Nodes: array{int,int}, Projects: array{int,int}, Instances: array{int,int}, Processes: array{int,int}, Schedules: array{int,int}, Databases: array{int,int}, Firewall: array{int,int}} */
    public function counts(): array
    {
        $off = static fn (array $rows, callable $ok): int => count(array_filter($rows, static fn (array $row): bool => ! $ok($row)));

        return [
            'Nodes' => [count($this->nodes), $off($this->nodes, self::nodeHealthy(...))],
            'Projects' => [count($this->apps), 0],
            'Instances' => [count($this->instances), $off($this->instances, self::instanceHealthy(...))],
            'Processes' => [count($this->processes), $off($this->processes, self::processHealthy(...))],
            'Schedules' => [count($this->schedules), $off($this->schedules, self::scheduleHealthy(...))],
            // Databases has no health concept today (no request surfaces a connection's runtime
            // state), so its warn count is always 0.
            'Databases' => [count($this->databases), 0],
            'Firewall' => [count($this->firewall), $off($this->firewall, self::firewallHealthy(...))],
        ];
    }

    // ---- health: whether a row is "yellow" (needs a look), in the Gateway's own vocabulary ----
    //
    // A record's provisioning `status` and, where it exists, its separate runtime/desired state
    // use different enums (see apps/gateway/app/Domain/**): comparing them literally, or against
    // a value from the wrong enum, produces false positives. These are the one place that decides
    // "needs attention" per family; Screen and attentionRows()/counts() above all call through
    // them instead of repeating the comparison.

    /**
     * A Node's `status` is LifecycleStatus: provisioning, active, failed, removing.
     *
     * @param  array<string, mixed>  $node
     */
    public static function nodeHealthy(array $node): bool
    {
        return $node['status'] === 'active';
    }

    /**
     * An AppInstance's `status` is AppInstanceState; active is the only settled, healthy state.
     *
     * @param  array<string, mixed>  $instance
     */
    public static function instanceHealthy(array $instance): bool
    {
        return $instance['status'] === 'active';
    }

    /**
     * A Process's `desired_state` is DesiredProcessState (running, stopped); its `runtime_status`
     * is not the same vocabulary, and — confirmed against a live fleet — is not even one fixed
     * vocabulary: it is whatever the process's own `runtime` reports verbatim. A systemd process
     * reports `systemctl is-active` (active, inactive, failed, activating, deactivating,
     * maintenance, absent, ...); a Docker process reports `docker container inspect`'s
     * `.State.Status` (running, exited, created, paused, restarting, removing, dead, ...) — a
     * running Docker container's healthy value is literally "running", never "active". A running
     * process is healthy when its runtime reports its own active value; a stopped one is healthy
     * when its runtime reports its own inactive value. Anything else (still settling, failed, or
     * an unrecognized runtime) needs a look.
     *
     * @param  array<string, mixed>  $process
     */
    public static function processHealthy(array $process): bool
    {
        [$activeValue, $inactiveValue] = self::processRuntimeVocabulary($process);

        return match ($process['desired_state']) {
            'running' => $process['runtime_status'] === $activeValue,
            'stopped' => $process['runtime_status'] === $inactiveValue,
            default => false,
        };
    }

    /**
     * Whether a Process is currently running, regardless of its desired_state — the systemd
     * "active"/Docker "running" runtime_status value, in whichever vocabulary its own runtime
     * reports. Used to offer "stop" instead of "start" in the record actions menu, and by
     * processHealthy() above for a process whose desired_state is "running".
     *
     * @param  array<string, mixed>  $process
     */
    public static function processRuntimeIsActive(array $process): bool
    {
        [$activeValue] = self::processRuntimeVocabulary($process);

        return $process['runtime_status'] === $activeValue;
    }

    /**
     * @param  array<string, mixed>  $process
     * @return array{string, string} The [active, inactive] runtime_status values this process's
     *                               own runtime reports, e.g. ['active', 'inactive'] for systemd
     *                               or ['running', 'exited'] for Docker.
     */
    private static function processRuntimeVocabulary(array $process): array
    {
        return ($process['runtime'] ?? null) === 'docker' ? ['running', 'exited'] : ['active', 'inactive'];
    }

    /**
     * A Schedule's `desired_timer_state` is DesiredTimerState (enabled, disabled); its `status`
     * is a separate LifecycleStatus-shaped provisioning status (provisioning, active, failed,
     * removing). Healthy means the timer is enabled and provisioning did not fail.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function scheduleHealthy(array $schedule): bool
    {
        return $schedule['desired_timer_state'] === 'enabled' && $schedule['status'] !== 'failed';
    }

    /**
     * A Firewall rule's `status` is LifecycleStatus; active is its healthy, applied state.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function firewallHealthy(array $rule): bool
    {
        return $rule['status'] === 'active';
    }

    /**
     * A deployment's `status` is running, succeeded, or failed; succeeded is the settled,
     * healthy state.
     *
     * @param  array<string, mixed>  $deployment
     */
    public static function deploymentHealthy(array $deployment): bool
    {
        return $deployment['status'] === 'succeeded';
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
            'cpu' => $process->cpu,
            'memory_bytes' => $process->memoryBytes,
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
