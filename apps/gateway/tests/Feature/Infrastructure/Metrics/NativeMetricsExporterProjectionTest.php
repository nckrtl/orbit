<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

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

    $projection = new NativeMetricsExporterProjection(new ExporterSelector, $preferences);
    $items = $projection->for($metrics);

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

    foreach ($items as $item) {
        $direct = $projection->forNode($metrics, $item->node);

        expect($direct)
            ->not
            ->toBeNull()
            ->and([
                $direct->node->name,
                $direct->selection->selected,
                $direct->selection->reason->value,
            ])
            ->toBe([
                $item->node->name,
                $item->selection->selected,
                $item->selection->reason->value,
            ]);
    }
});

it('returns no direct projection for inactive or missing nodes', function (): void {
    $metrics = metricsExporterProjectionNode('metrics');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $inactive = metricsExporterProjectionNode('inactive', 'failed');
    $missing = metricsExporterProjectionNode('missing');
    $missing->delete();
    $projection = new NativeMetricsExporterProjection(
        new ExporterSelector,
        app(ExporterPreferenceRepository::class),
    );

    expect($projection->forNode($metrics, $inactive))
        ->toBeNull()
        ->and($projection->forNode($metrics, $missing))
        ->toBeNull();
});

it('reads fresh role and preference state with constant direct preference-query work', function (): void {
    $metrics = metricsExporterProjectionNode('metrics');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $target = metricsExporterProjectionNode('target');
    $target->load('roles');
    $target->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $preferences = app(ExporterPreferenceRepository::class);
    $projection = new NativeMetricsExporterProjection(new ExporterSelector, $preferences);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $before = $projection->forNode($metrics, $target);
    $beforePreferenceQueries = metricsExporterPreferenceQueryCount();

    foreach (range(1, 20) as $index) {
        $unrelated = metricsExporterProjectionNode("unrelated-{$index}");
        $preferences->put($unrelated->id, ExporterPreference::Enabled);
    }
    $preferences->put($target->id, ExporterPreference::Disabled);

    DB::flushQueryLog();
    $after = $projection->forNode($metrics, $target);
    $afterPreferenceQueries = metricsExporterPreferenceQueryCount();
    DB::disableQueryLog();

    expect($before)
        ->not->toBeNull()->and($before->selection->reason->value)->toBe('role_default')->and($after)
        ->not->toBeNull()->and($after->selection->reason->value)->toBe('explicit_disabled')->and(
            $beforePreferenceQueries,
        )->toBe(1)->and($afterPreferenceQueries)->toBe(1);
});

function metricsExporterPreferenceQueryCount(): int
{
    return count(array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => (
            str_contains($query['query'], '"settings"')
            && in_array('metrics.exporter.preference', $query['bindings'], strict: true)
        ),
    ));
}

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
