<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\App;
use App\Models\Node;

interface AppStateInspector
{
    public function inspect(App $app, Node $node): AppInspectionData;
}
