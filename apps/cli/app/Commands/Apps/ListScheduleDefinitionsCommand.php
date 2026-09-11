<?php

declare(strict_types=1);

namespace App\Commands\Apps;

final class ListScheduleDefinitionsCommand extends ListAppRuntimeDefinitionsCommand
{
    #[\Override]
    protected $signature = 'app:schedule-definitions
        {app : Numeric App ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List Schedule definitions for one App.';

    protected function definitionKind(): string
    {
        return 'schedule';
    }

    protected function definitionLabel(): string
    {
        return 'Schedule';
    }
}
