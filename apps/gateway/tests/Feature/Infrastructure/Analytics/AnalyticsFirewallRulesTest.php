<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

/** @return list<array{string, string, string, ?string}> */
function analytics_rule_shapes(Node $node): array
{
    return array_map(
        static fn (UfwManagedRule $rule): array => [$rule->shape->comment, $rule->shape->port, $rule->shape->destination, $rule->shape->inInterface],
        new NodeFirewallRuleCatalog()->forRole($node, RoleName::Analytics),
    );
}

describe('the analytics role firewall rules', function (): void {
    it('admits the Docker bridge to a storage Process that shares the role\'s Node', function (): void {
        $storage = analytics_storage_processes();
        app(AnalyticsRoleSettingsRepository::class)->store(
            $storage['node'],
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
        );

        expect(analytics_rule_shapes($storage['node']))->toBe([
            ['orbit:analytics-https', '443', '10.44.0.200', 'orbit'],
            ['orbit:analytics-postgres-local', '5432', '10.44.0.200', 'docker0'],
            ['orbit:analytics-clickhouse-local', '8123', '10.44.0.200', 'docker0'],
        ]);
    });

    it('adds no local rule for storage on another Node, which WireGuard already admits', function (): void {
        $storage = analytics_storage_processes();
        $analytics = analytics_database_node('analytics-only', '10.44.0.201');
        app(AnalyticsRoleSettingsRepository::class)->store(
            $analytics,
            new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
        );

        expect(analytics_rule_shapes($analytics))->toBe([['orbit:analytics-https', '443', '10.44.0.201', 'orbit']]);
    });

    it('has only the dashboard rule before any storage is recorded', function (): void {
        $node = analytics_database_node();

        expect(analytics_rule_shapes($node))->toBe([['orbit:analytics-https', '443', '10.44.0.200', 'orbit']]);
    });
});
