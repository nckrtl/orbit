<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

interface TaskWorkspacePreparer
{
    public function prepare(AppInstance $instance): void;
}
