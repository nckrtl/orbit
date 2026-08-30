<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;

final readonly class ExporterDegradationRepository
{
    public const string KEY = 'metrics.exporter.degradation';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function get(int $nodeId): ?ExporterDegradationReason
    {
        $value = $this->settings->get($this->scope($nodeId), self::KEY);

        return $value === null ? null : ExporterDegradationReason::tryFrom($value);
    }

    public function put(int $nodeId, ExporterDegradationReason $reason): void
    {
        $this->settings->put($this->scope($nodeId), self::KEY, $reason->value);
    }

    public function forget(int $nodeId): void
    {
        $this->settings->delete($this->scope($nodeId), self::KEY);
    }

    private function scope(int $nodeId): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $nodeId);
    }
}
