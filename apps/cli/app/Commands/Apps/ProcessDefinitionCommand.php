<?php

declare(strict_types=1);

namespace App\Commands\Apps;

final class ProcessDefinitionCommand extends ManageAppRuntimeDefinitionCommand
{
    #[\Override]
    protected $signature = 'app:process-definition
        {app : Numeric App ID}
        {--id= : Definition UUID for show, replace, or remove}
        {--file= : JSON definition file for create or replace}
        {--remove : Remove the definition selected by --id}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show, create, replace, or remove one App process definition.';

    protected function definitionKind(): string
    {
        return 'process';
    }

    protected function definitionLabel(): string
    {
        return 'Process';
    }
}
