<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspectionOutcome;
use App\Models\Tool;

trait InspectsToolsIndividually
{
    /**
     * @param  list<Tool>  $tools
     * @return list<ToolInspectionOutcome>
     */
    public function inspectMany(array $tools): array
    {
        return array_map(function (Tool $tool): ToolInspectionOutcome {
            try {
                return ToolInspectionOutcome::verified($this->inspect($tool));
            } catch (ToolInspectionException) {
                return ToolInspectionOutcome::failed();
            }
        }, array_values($tools));
    }
}
