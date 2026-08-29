<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Nodes\RoleName;

describe(ExporterSelector::class, function (): void {
    it('selects exporters from tri-state preference and role state', function (): void {
        $selector = new ExporterSelector;

        expect($selector->select([RoleName::AppDev])->reason)
            ->toBe(ExporterSelectionReason::RoleDefault)
            ->and($selector->select([])->reason)
            ->toBe(ExporterSelectionReason::RolelessDefaultExcluded)
            ->and($selector->select([], ExporterPreference::Enabled)->selected)
            ->toBeTrue()
            ->and($selector->select([RoleName::AppDev], ExporterPreference::Disabled)->selected)
            ->toBeFalse()
            ->and($selector->select([RoleName::Metrics], ExporterPreference::Disabled, true)->reason)
            ->toBe(ExporterSelectionReason::MetricsNode);
    });
});
