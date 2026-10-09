<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compute\ComputeException;
use App\Infrastructure\Compute\SandboxImageRetention;
use App\Infrastructure\Compute\UpCloudImageBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** One scheduler tick of the UpCloud sandbox base template build (ADR 0204). */
final class BuildSandboxImageCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:sandbox-image-build {--now : Start a build now when none is running}';

    #[\Override]
    protected $description = 'Advance the UpCloud sandbox base template build by one step and delete unused old templates.';

    public function handle(UpCloudImageBuilder $builder, SandboxImageRetention $retention): int
    {
        $lock = Cache::lock('orbit:compute:upcloud-image', 900);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $image = $builder->active();
            if ($image === null && ($this->option('now') || $builder->due())) {
                $image = $builder->reserve();
            }
            if ($image !== null) {
                $image = $builder->advance($image);
                $this->line(sprintf('%s %s %s%s', $image->id, $image->status->value, $image->step->value, $image->error_code === null ? '' : ' '.$image->error_code));
            }
            foreach ($retention->prune() as $template) {
                $this->line('Deleted template '.$template);
            }
        } catch (ComputeException $exception) {
            if ($exception->errorCode !== 'compute.busy') {
                $this->error($exception->errorCode.': '.$exception->getMessage());

                return self::FAILURE;
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
