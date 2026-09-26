<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Logs\LogRedactor;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\Process;
use SensitiveParameter;

final readonly class ShowProcessLogsAction
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private LogRedactor $redactor,
    ) {}

    public function execute(#[SensitiveParameter] Process $process, int $lines): string
    {
        return $this->redactor->redact($this->runtime->logs($process, $lines), $this->redactor->valuesFor($process));
    }
}
