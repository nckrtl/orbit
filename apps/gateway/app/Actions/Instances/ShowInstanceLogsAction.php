<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Logs\InstanceLogReader;
use App\Domain\Logs\LogRedactor;
use App\Models\Instance;

final readonly class ShowInstanceLogsAction
{
    public function __construct(
        private InstanceLogReader $logs,
        private LogRedactor $redactor,
    ) {}

    public function execute(Instance $instance, int $lines): string
    {
        return $this->redactor->redact($this->logs->tail($instance, $lines), $this->redactor->valuesFor($instance));
    }
}
