<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\RoleName;

final readonly class ExporterSelector
{
    /** @param list<RoleName> $roles */
    public function select(
        array $roles,
        ?ExporterPreference $preference = null,
        bool $isMetricsNode = false,
    ): ExporterSelection {
        if ($isMetricsNode) {
            return new ExporterSelection(true, ExporterSelectionReason::MetricsNode);
        }

        if ($preference === ExporterPreference::Enabled) {
            return new ExporterSelection(true, ExporterSelectionReason::ExplicitEnabled);
        }

        if ($preference === ExporterPreference::Disabled) {
            return new ExporterSelection(false, ExporterSelectionReason::ExplicitDisabled);
        }

        $hasActiveRole = $roles !== [];

        return new ExporterSelection(
            $hasActiveRole,
            $hasActiveRole
                ? ExporterSelectionReason::RoleDefault
                : ExporterSelectionReason::RolelessDefaultExcluded,
        );
    }
}
