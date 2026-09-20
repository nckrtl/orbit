<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskExtensionStatusData;
use App\Domain\Tasks\TaskExtensionState;

final readonly class ShowTasksStatusAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): TaskExtensionStatusData
    {
        return new TaskExtensionStatusData(enabled: $this->extension->enabled());
    }
}
