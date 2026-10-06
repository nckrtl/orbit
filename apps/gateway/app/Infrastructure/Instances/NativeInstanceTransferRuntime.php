<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Instance;
use App\Models\Node;

final readonly class NativeInstanceTransferRuntime implements InstanceTransferRuntime
{
    public function __construct(
        private ProcessRuntimeManager $processes,
        private ScheduleRuntimeManager $schedules,
    ) {}

    public function pause(Instance $instance): void
    {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            // status() verifies ownership and makes a retry skip stop when the artifact is already absent.
            if ($this->processes->status($process) !== 'absent') {
                $this->processes->stop($process);
            }

            $this->processes->remove($process);
        }

        foreach ($instance->schedules as $schedule) {
            $this->schedules->remove($schedule, cascade: true);
        }
    }

    public function restore(Instance $instance): void
    {
        $instance->loadMissing(['processes', 'schedules', 'node']);

        foreach ($instance->processes as $process) {
            $this->processes->converge($process);

            if ($process->desired_state === DesiredProcessState::Running) {
                $this->processes->start($process);
            }
        }

        foreach ($instance->schedules as $schedule) {
            $this->schedules->install($schedule);

            if ($schedule->desired_timer_state === DesiredTimerState::Enabled) {
                $this->schedules->activate($schedule);
            }
        }
    }

    public function relocate(
        Instance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            $process->update([
                'working_directory' => $this->relocatedPath($process->working_directory, $sourcePath, $workingDirectory),
                ...(($process->isVpDev() || $process->isAnnotator()) ? ['runtime_config' => [...$process->runtime_config, 'environment_file' => ApplicationDirectory::resolvePath($workingDirectory, $instance->applicationPath($process->app)).'/.env']] : []),
            ]);
        }

        foreach ($instance->schedules as $schedule) {
            $schedule->update(['host_node_id' => $destination->id]);
        }
    }

    public function activate(Instance $instance): void
    {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            $this->processes->converge($process->refresh());

            if ($process->desired_state === DesiredProcessState::Running) {
                $this->processes->start($process);
            }
        }

        foreach ($instance->schedules as $schedule) {
            $this->schedules->install($schedule->refresh());

            if ($schedule->desired_timer_state === DesiredTimerState::Enabled) {
                $this->schedules->activate($schedule);
            }
        }
    }

    public function cleanupSourceArtifacts(Instance $instance, Node $sourceNode, string $sourcePath): void
    {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            // Runtime operations resolve the current owner Node. pause() already removed source units.
            if ($instance->node_id === $sourceNode->id && $this->pathOnSource($process->working_directory, $sourcePath)) {
                $this->processes->remove($process);
            }
        }

        foreach ($instance->schedules as $schedule) {
            if ($schedule->host_node_id === $sourceNode->id) {
                $this->schedules->remove($schedule, cascade: true);
            }
        }
    }

    private function relocatedPath(string $current, string $sourcePath, string $destinationPath): string
    {
        if ($current === $sourcePath) {
            return $destinationPath;
        }

        if (str_starts_with($current, $sourcePath.'/')) {
            return $destinationPath.substr($current, strlen($sourcePath));
        }

        return $current;
    }

    private function pathOnSource(string $path, string $sourcePath): bool
    {
        return $path === $sourcePath || str_starts_with($path, $sourcePath.'/');
    }
}
