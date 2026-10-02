<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

final readonly class ExporterDegradationRepository
{
    public const string KEY = 'metrics.exporter.degradation';

    public const string ServiceErrorKey = 'metrics.service.degradation.error_code';

    private const string ServiceStepKey = 'metrics.service.degradation.step';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function get(int $nodeId): ?ExporterDegradationReason
    {
        $value = $this->settings->get($this->scope($nodeId), self::KEY);

        return $value === null
            ? ($this->step($nodeId) === null ? null : ExporterDegradationReason::ReconcileFailed)
            : ExporterDegradationReason::tryFrom($value);
    }

    public function hasExporterDegradation(int $nodeId): bool
    {
        return $this->settings->get($this->scope($nodeId), self::KEY) !== null;
    }

    public function put(int $nodeId, ExporterDegradationReason $reason): void
    {
        $this->settings->put($this->scope($nodeId), self::KEY, $reason->value);
    }

    public function recordReconcileFailure(int $nodeId, string $step, string $errorCode): void
    {
        // Service failures survive exporter/cAdvisor recovery and their shared degradation cleanup.
        DB::transaction(function () use ($nodeId, $step, $errorCode): void {
            $this->settings->put($this->scope($nodeId), self::ServiceStepKey, $step);
            $this->settings->put($this->scope($nodeId), self::ServiceErrorKey, $errorCode);
        });
    }

    public function step(int $nodeId): ?string
    {
        return $this->settings->get($this->scope($nodeId), self::ServiceStepKey);
    }

    /** @param list<int> $nodeIds */
    public function forgetServiceFailures(array $nodeIds): void
    {
        // Commit all recovered Nodes together, after remote convergence and publication succeed.
        DB::transaction(function () use ($nodeIds): void {
            foreach ($nodeIds as $nodeId) {
                $this->settings->delete($this->scope($nodeId), self::ServiceStepKey);
                $this->settings->delete($this->scope($nodeId), self::ServiceErrorKey);
            }
        });
    }

    public function forget(int $nodeId): void
    {
        $this->settings->delete($this->scope($nodeId), self::KEY);
    }

    public function forgetReconcileFailures(): void
    {
        Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('key', self::KEY)
            ->where('value', ExporterDegradationReason::ReconcileFailed->value)
            ->delete();
    }

    private function scope(int $nodeId): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $nodeId);
    }
}
