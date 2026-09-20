<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Settings\SettingScopeType;
use App\Infrastructure\Analytics\NativeAnalyticsRoleSettingsRepository;
use App\Models\Setting;

describe(NativeAnalyticsRoleSettingsRepository::class, function (): void {
    it('is the bound analytics role settings repository', function (): void {
        expect(app(AnalyticsRoleSettingsRepository::class))->toBeInstanceOf(NativeAnalyticsRoleSettingsRepository::class);
    });

    it('stores the two Process IDs as plain settings in the Node scope', function (): void {
        $node = analytics_database_node('services');
        $other = analytics_database_node('other', '10.44.0.201');
        $repository = app(AnalyticsRoleSettingsRepository::class);

        $repository->store($node, new AnalyticsRoleSettings(11, 12));
        $repository->store($node, new AnalyticsRoleSettings(21, 22));

        $rows = Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('scope_id', $node->id)
            ->orderBy('key')
            ->get();

        expect($rows->pluck('value', 'key')->all())
            ->toBe([
                'analytics.clickhouse_process_id' => '22',
                'analytics.postgres_process_id' => '21',
            ])
            ->and($rows->pluck('is_secret')->unique()->all())
            ->toBe([false])
            ->and($repository->find($node))
            ->toEqual(new AnalyticsRoleSettings(21, 22))
            ->and($repository->find($other))
            ->toBeNull();
    });

    it('finds nothing when one Process ID is absent', function (): void {
        $node = analytics_database_node('services');
        $repository = app(AnalyticsRoleSettingsRepository::class);
        $repository->store($node, new AnalyticsRoleSettings(11, 12));
        Setting::query()->where('key', 'analytics.clickhouse_process_id')->delete();

        expect($repository->find($node))->toBeNull();
    });

    it('purges only the settings of the given Node', function (): void {
        $node = analytics_database_node('services');
        $other = analytics_database_node('other', '10.44.0.201');
        $repository = app(AnalyticsRoleSettingsRepository::class);
        $repository->store($node, new AnalyticsRoleSettings(11, 12));
        $repository->store($other, new AnalyticsRoleSettings(31, 32));

        $repository->purge($node);

        expect($repository->find($node))
            ->toBeNull()
            ->and($repository->find($other))
            ->toEqual(new AnalyticsRoleSettings(31, 32));
    });
});
