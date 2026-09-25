<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskExtensionStatusData;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskExtensionState;

final readonly class EnableTasksAction
{
    public function __construct(private TaskExtensionState $extension, private TaskBroadcasts $broadcasts) {}

    public function execute(): TaskExtensionStatusData
    {
        $this->extension->enable();
        $this->broadcasts->extensionChanged(true);

        return new TaskExtensionStatusData(enabled: true);
    }
}
