<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;

final readonly class ExporterPreferenceRepository
{
    public const string KEY = 'metrics.exporter.preference';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function get(int $nodeId): ?ExporterPreference
    {
        $value = $this->settings->get(new SettingScope(SettingScopeType::Node, $nodeId), self::KEY);

        return $value === null ? null : ExporterPreference::tryFrom($value);
    }

    public function put(int $nodeId, ExporterPreference $preference): void
    {
        $this->settings->put(new SettingScope(SettingScopeType::Node, $nodeId), self::KEY, $preference->value);
    }
}
