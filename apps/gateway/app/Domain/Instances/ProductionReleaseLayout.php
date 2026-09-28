<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface ProductionReleaseLayout
{
    public function validateCurrent(Instance $instance): void;

    public function clearCurrent(Instance $instance): void;
}
