<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Tool;

interface ToolInspector
{
    public function inspect(Tool $tool): ToolInspectionData;
}
