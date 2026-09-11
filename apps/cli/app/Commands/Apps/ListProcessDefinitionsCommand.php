<?php

declare(strict_types=1);

namespace App\Commands\Apps;

final class ListProcessDefinitionsCommand extends ListAppRuntimeDefinitionsCommand
{
    #[\Override]
    protected $signature = 'app:process-definitions
        {app : Numeric App ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List process definitions for one App.';

    protected function definitionKind(): string
    {
        return 'process';
    }

    protected function definitionLabel(): string
    {
        return 'Process';
    }
}
