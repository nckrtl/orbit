<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;

final readonly class RequireTasksExtensionAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): void
    {
        if ($this->extension->enabled()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'tasks.disabled',
            message: __('The tasks extension is disabled.'),
            status: 409,
        );
    }
}
