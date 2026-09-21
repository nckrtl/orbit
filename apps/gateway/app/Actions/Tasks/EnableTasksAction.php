<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskExtensionStatusData;
use App\Domain\Tasks\TaskExtensionState;

final readonly class EnableTasksAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): TaskExtensionStatusData
    {
        $this->extension->enable();

        return new TaskExtensionStatusData(enabled: true);
    }
}
