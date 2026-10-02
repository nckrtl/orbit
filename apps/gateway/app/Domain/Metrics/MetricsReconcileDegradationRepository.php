<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Models\Setting;

final readonly class MetricsReconcileDegradationRepository
{
    public const string KEY = 'metrics.reconcile.degradation';

    public function __construct(private SettingRepository $settings = new SettingRepository) {}

    public function errorCode(int $nodeId): ?string
    {
        return $this->settings->get($this->scope($nodeId), self::KEY)
            ?? $this->settings->get($this->scope($nodeId), ExporterDegradationRepository::ServiceErrorKey);
    }

    public function put(int $nodeId, string $errorCode): void
    {
        $this->settings->put($this->scope($nodeId), self::KEY, $errorCode);
    }

    public function forget(int $nodeId): void
    {
        $this->settings->delete($this->scope($nodeId), self::KEY);
    }

    public function forgetAll(): void
    {
        Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('key', self::KEY)
            ->delete();
    }

    private function scope(int $nodeId): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $nodeId);
    }
}
