<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Node;

final class NullToolManagerMaterializer implements ToolManagerMaterializer
{
    public function converge(Node $node): void {}
}
