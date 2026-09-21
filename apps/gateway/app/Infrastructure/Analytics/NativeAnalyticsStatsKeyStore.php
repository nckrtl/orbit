<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use SensitiveParameter;

/**
 * Gateway-scoped Stats API key, so a relocated analytics role still has it
 * ([ADR 0102](/decisions/0102-read-app-instance-analytics-through-a-fleet-driver)).
 */
final readonly class NativeAnalyticsStatsKeyStore implements AnalyticsStatsKeyStore
{
    public const string SettingKey = 'analytics.stats_api_key';

    public function __construct(private SettingRepository $settings) {}

    public function get(): ?string
    {
        $stored = $this->settings->get($this->scope(), self::SettingKey);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    public function put(#[SensitiveParameter] string $key): void
    {
        $this->settings->put($this->scope(), self::SettingKey, $key, SettingValueProtection::Secret);
    }

    public function clear(): void
    {
        $this->settings->delete($this->scope(), self::SettingKey);
    }

    public function configured(): bool
    {
        return $this->get() !== null;
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
