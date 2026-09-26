<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Logs\AppInstanceLogReader;
use App\Domain\Logs\LogRedactor;
use App\Models\AppInstance;

final readonly class ShowAppInstanceLogsAction
{
    public function __construct(
        private AppInstanceLogReader $logs,
        private LogRedactor $redactor,
    ) {}

    public function execute(AppInstance $instance, int $lines): string
    {
        return $this->redactor->redact($this->logs->tail($instance, $lines), $this->redactor->valuesFor($instance));
    }
}
