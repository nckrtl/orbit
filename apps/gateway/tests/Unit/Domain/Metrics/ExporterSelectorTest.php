<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Nodes\RoleName;

describe(ExporterSelector::class, function (): void {
    it('selects exporters from tri-state preference and role state', function (): void {
        $selector = new ExporterSelector;

        expect($selector->select([RoleName::AppDev], eligible: true)->reason)
            ->toBe(ExporterSelectionReason::RoleDefault)
            ->and($selector->select([], eligible: true)->reason)
            ->toBe(ExporterSelectionReason::RolelessDefaultExcluded)
            ->and($selector->select([], true, ExporterPreference::Enabled)->selected)
            ->toBeTrue()
            ->and($selector->select([RoleName::AppDev], true, ExporterPreference::Disabled)->selected)
            ->toBeFalse()
            ->and($selector->select([RoleName::Metrics], true, ExporterPreference::Disabled, true)->reason)
            ->toBe(ExporterSelectionReason::MetricsNode);
    });

    it('requires eligibility for the Gateway role default and Metrics on the Gateway', function (): void {
        $selector = new ExporterSelector;

        expect($selector->select([RoleName::Gateway], eligible: true)->reason)
            ->toBe(ExporterSelectionReason::RoleDefault)
            ->and($selector->select([RoleName::Gateway], eligible: false)->reason)
            ->toBe(ExporterSelectionReason::Ineligible)
            ->and($selector->select(
                [RoleName::Gateway, RoleName::Metrics],
                true,
                ExporterPreference::Disabled,
                isMetricsNode: true,
            )->reason)
            ->toBe(ExporterSelectionReason::MetricsNode)
            ->and($selector->select(
                [RoleName::Gateway, RoleName::Metrics],
                false,
                ExporterPreference::Disabled,
                isMetricsNode: true,
            )->reason)
            ->toBe(ExporterSelectionReason::Ineligible);
    });

    it('excludes an ineligible node for every preference and default', function (
        ?ExporterPreference $preference,
        bool $isMetricsNode,
    ): void {
        $selection = new ExporterSelector()->select(
            [RoleName::AppProd],
            false,
            $preference,
            $isMetricsNode,
        );

        expect($selection->selected)
            ->toBeFalse()
            ->and($selection->reason)
            ->toBe(ExporterSelectionReason::Ineligible);
    })->with([
        'absent preference' => [null, false],
        'enabled preference' => [ExporterPreference::Enabled, false],
        'disabled preference' => [ExporterPreference::Disabled, false],
        'metrics default' => [null, true],
    ]);
});
