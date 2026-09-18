<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Metrics\NodeMetricsProbe;
use App\Support\Console\ConsoleWriter;
use LaravelZero\Framework\Commands\Command;

final class InternalNodeMetricsCommand extends Command
{
    #[\Override]
    protected $signature = 'internal:node-metrics';

    #[\Override]
    protected $description = 'Capture one local metrics snapshot from this Node and print it as JSON.';

    #[\Override]
    protected $hidden = true;

    public function handle(NodeMetricsProbe $probe): int
    {
        ConsoleWriter::write(
            $this->output,
            json_encode($probe->capture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
        );

        return self::SUCCESS;
    }
}
