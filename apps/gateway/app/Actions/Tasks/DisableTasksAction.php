<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskExtensionStatusData;
use App\Domain\Tasks\TaskExtensionState;

final readonly class DisableTasksAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): TaskExtensionStatusData
    {
        $this->extension->disable();

        return new TaskExtensionStatusData(enabled: false);
    }
}
