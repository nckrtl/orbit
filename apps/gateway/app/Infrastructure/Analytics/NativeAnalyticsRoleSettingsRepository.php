<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\Node;

final readonly class NativeAnalyticsRoleSettingsRepository implements AnalyticsRoleSettingsRepository
{
    public const string PostgresProcessKey = 'analytics.postgres_process_id';

    public const string ClickhouseProcessKey = 'analytics.clickhouse_process_id';

    public const string VersionKey = 'analytics.plausible_version';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function find(Node $node): ?AnalyticsRoleSettings
    {
        $scope = $this->scope($node);
        $postgres = $this->processId($scope, self::PostgresProcessKey);
        $clickhouse = $this->processId($scope, self::ClickhouseProcessKey);

        if ($postgres === null || $clickhouse === null) {
            return null;
        }

        return new AnalyticsRoleSettings($postgres, $clickhouse);
    }

    public function store(Node $node, AnalyticsRoleSettings $settings): void
    {
        $scope = $this->scope($node);

        $this->settings->put(
            $scope,
            self::PostgresProcessKey,
            (string) $settings->postgresProcessId,
            SettingValueProtection::Plain,
        );
        $this->settings->put(
            $scope,
            self::ClickhouseProcessKey,
            (string) $settings->clickhouseProcessId,
            SettingValueProtection::Plain,
        );
    }

    public function version(Node $node): string
    {
        return $this->settings->get($this->scope($node), self::VersionKey)
            ?? (string) config('orbit.analytics.plausible_version');
    }

    public function storeVersion(Node $node, string $version): void
    {
        $this->settings->put($this->scope($node), self::VersionKey, $version, SettingValueProtection::Plain);
    }

    public function purge(Node $node): void
    {
        $scope = $this->scope($node);

        $this->settings->delete($scope, self::VersionKey);
        $this->settings->delete($scope, self::PostgresProcessKey);
        $this->settings->delete($scope, self::ClickhouseProcessKey);
    }

    private function processId(SettingScope $scope, string $key): ?int
    {
        $value = $this->settings->get($scope, $key);

        if ($value === null || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }
}
