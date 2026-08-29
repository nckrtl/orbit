<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;

interface NodeStateInspector
{
    public function inspect(Node $node): NodeInspectionData;
}
