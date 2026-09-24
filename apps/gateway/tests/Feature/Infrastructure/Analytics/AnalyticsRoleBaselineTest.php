<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Roles\AnalyticsRoleBaseline;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use Tests\Support\FakeNodeRoleFirewallManager;

/** What the baseline asked of its two collaborators, in order. */
final class AnalyticsRoleEvents
{
    /** @var list<string> */
    public array $events = [];

    public ?AnalyticsStorageConnection $storage = null;

    public ?string $secretKeyBase = null;
}

final readonly class RecordingPlausibleRuntime implements PlausibleRuntimeLifecycle
{
    public function __construct(private AnalyticsRoleEvents $log) {}

    public function converge(Node $node, string $version, AnalyticsStorageConnection $storage, string $secretKeyBase): Process
    {
        $this->log->events[] = "runtime:converge:{$version}";
        $this->log->storage = $storage;
        $this->log->secretKeyBase = $secretKeyBase;

        return new Process;
    }

    public function remove(Node $node): void
    {
        $this->log->events[] = 'runtime:remove';
    }

    public function forget(Node $node): void
    {
        $this->log->events[] = 'runtime:forget';
    }
}

final class RecordingClickhouseConfiguration implements AnalyticsClickhouseConfigurationManager
{
    public ?NodeRoleOperationException $failure = null;

    public function __construct(private readonly AnalyticsRoleEvents $log) {}

    public function converge(Process $clickhouse): void
    {
        $this->log->events[] = "clickhouse:converge:{$clickhouse->name}";

        if ($this->failure instanceof NodeRoleOperationException) {
            throw $this->failure;
        }
    }
}

final readonly class RecordingAnalyticsPublication implements AnalyticsPublicationManager
{
    public function __construct(private AnalyticsRoleEvents $log) {}

    public function converge(Node $node): void
    {
        $this->log->events[] = 'publication:converge';
    }

    public function remove(Node $node): void
    {
        $this->log->events[] = 'publication:remove';
    }

    public function removeUnreachable(Node $node): void
    {
        $this->log->events[] = 'publication:removeUnreachable';
    }
}

/** @return array{Node, NodeRole} */
function analytics_baseline_assignment(): array
{
    $node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.12',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.12',
    ]);

    return [$node, $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Provisioning])];
}

beforeEach(function (): void {
    $this->collaborators = new AnalyticsRoleEvents;
    $this->app->instance(PlausibleRuntimeLifecycle::class, new RecordingPlausibleRuntime($this->collaborators));
    $this->app->instance(AnalyticsPublicationManager::class, new RecordingAnalyticsPublication($this->collaborators));
    $this->clickhouse = new RecordingClickhouseConfiguration($this->collaborators);
    $this->app->instance(AnalyticsClickhouseConfigurationManager::class, $this->clickhouse);
    $this->firewall = new FakeNodeRoleFirewallManager;
    $this->app->instance(NodeRoleFirewallManager::class, $this->firewall);
});

describe(AnalyticsRoleBaseline::class, function (): void {
    it('applies Plausible\'s ClickHouse configuration, runs Plausible with the URLs the storage Processes declare, then publishes the dashboard', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $storage = analytics_storage_processes();
        app(AnalyticsRoleSettingsRepository::class)->store(
            $node,
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
        );

        app(AnalyticsRoleBaseline::class)->converge($node, $assignment);

        expect($this->collaborators->events)->toBe(['clickhouse:converge:plausible-clickhouse', 'runtime:converge:3.2.1', 'publication:converge'])
            ->and($this->firewall->commands)->toBe(['converge:analytics'])
            ->and($this->collaborators->storage?->databaseUrl)
            ->toBe('postgres://postgres:postgres-secret@10.44.0.200:5432/plausible_db')
            ->and($this->collaborators->storage?->clickhouseDatabaseUrl)
            ->toBe('http://plausible:clickhouse-secret@10.44.0.200:8123/plausible_events_db')
            ->and($this->collaborators->secretKeyBase)
            ->toBe(app(AnalyticsSecretManager::class)->secretKeyBase($node));
    });

    it('runs the version an update pinned', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $storage = analytics_storage_processes();
        $settings = app(AnalyticsRoleSettingsRepository::class);
        $settings->store($node, new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id));
        $settings->storeVersion($node, '3.3.0');

        app(AnalyticsRoleBaseline::class)->converge($node, $assignment);

        expect($this->collaborators->events[1])->toBe('runtime:converge:3.3.0');
    });

    it('refuses to converge, and starts nothing, when no storage Processes are recorded', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();

        expect(fn () => app(AnalyticsRoleBaseline::class)->converge($node, $assignment))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.settings_missing')
                    ->and($exception->status)
                    ->toBe(422),
            );
        expect($this->collaborators->events)->toBe([]);
    });

    it('refuses to converge, and starts nothing, when a recorded Process stopped being supported', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $storage = analytics_storage_processes();
        app(AnalyticsRoleSettingsRepository::class)->store(
            $node,
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
        );
        $storage['clickhouse']->delete();

        expect(fn () => app(AnalyticsRoleBaseline::class)->converge($node, $assignment))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.clickhouse_process_missing'),
            );
        expect($this->collaborators->events)->toBe([]);
    });

    it('stops before Plausible when the ClickHouse configuration fails', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $storage = analytics_storage_processes();
        app(AnalyticsRoleSettingsRepository::class)->store(
            $node,
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
        );
        $this->clickhouse->failure = new NodeRoleOperationException(
            'clickhouse-config',
            'node_role.convergence_failed',
            'analytics.clickhouse_config_failed',
            'ClickHouse configuration failed.',
        );

        expect(fn () => app(AnalyticsRoleBaseline::class)->converge($node, $assignment))
            ->toThrow(fn (NodeRoleOperationException $exception) => expect($exception->step)->toBe('clickhouse-config'));
        expect($this->collaborators->events)->toBe(['clickhouse:converge:plausible-clickhouse'])
            ->and($this->firewall->commands)->toBe([]);
    });

    it('removes the dashboard before Plausible, then forgets the secret and the settings', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $settings = app(AnalyticsRoleSettingsRepository::class);
        $settings->store($node, new AnalyticsRoleSettings(11, 12));
        $secret = app(AnalyticsSecretManager::class)->secretKeyBase($node);

        app(AnalyticsRoleBaseline::class)->remove($node, $assignment, purgeData: true);

        expect($this->collaborators->events)->toBe(['publication:remove', 'runtime:remove'])
            ->and($this->firewall->commands)->toBe(['remove:analytics'])
            ->and($settings->find($node))->toBeNull()
            ->and(app(AnalyticsSecretManager::class)->secretKeyBase($node))->not->toBe($secret);
    });

    it('removes only what lives on the Gateway for a node it cannot reach', function (): void {
        [$node, $assignment] = analytics_baseline_assignment();
        $settings = app(AnalyticsRoleSettingsRepository::class);
        $settings->store($node, new AnalyticsRoleSettings(11, 12));

        app(AnalyticsRoleBaseline::class)->removeUnreachable($node, $assignment);

        expect($this->collaborators->events)->toBe(['publication:removeUnreachable', 'runtime:forget'])
            ->and($settings->find($node))->toBeNull();
    });
});
