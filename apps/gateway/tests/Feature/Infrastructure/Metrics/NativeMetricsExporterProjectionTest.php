<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Node;

it('projects active nodes from prospective roles and explicit preferences once', function (): void {
    $metrics = metricsExporterProjectionNode('metrics');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'provisioning']);
    $active = metricsExporterProjectionNode('active-role');
    $active->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $provisioning = metricsExporterProjectionNode('provisioning-role');
    $provisioning->roles()->create(['role' => 'app-prod', 'status' => 'provisioning']);
    $failed = metricsExporterProjectionNode('failed-role');
    $failed->roles()->create(['role' => 'gateway', 'status' => 'failed']);
    $explicit = metricsExporterProjectionNode('explicit');
    $inactive = metricsExporterProjectionNode('inactive', 'failed');
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($active->id, ExporterPreference::Disabled);
    $preferences->put($explicit->id, ExporterPreference::Enabled);

    $items = new NativeMetricsExporterProjection(new ExporterSelector, $preferences)->for($metrics);

    expect(array_map(
        static fn (MetricsExporterProjectionItem $item): array => [
            $item->node->name,
            $item->selection->selected,
            $item->selection->reason->value,
        ],
        $items,
    ))->toBe([
        ['metrics',           true,  'metrics_node'],
        ['active-role',       false, 'explicit_disabled'],
        ['provisioning-role', true,  'role_default'],
        ['failed-role',       false, 'roleless_default_excluded'],
        ['explicit',          true,  'explicit_enabled'],
    ]);
});

function metricsExporterProjectionNode(string $name, string $status = 'active'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'ssh_user' => 'orbit',
    ]);
}
