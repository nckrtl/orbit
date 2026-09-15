<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Transfer\AppInstanceTransferRuntime;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;

final readonly class NativeAppInstanceTransferRuntime implements AppInstanceTransferRuntime
{
    public function __construct(
        private ProcessRuntimeManager $processes,
        private ScheduleRuntimeManager $schedules,
    ) {}

    public function pause(AppInstance $instance): void
    {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            $this->processes->stop($process);
            $this->processes->remove($process);
        }

        foreach ($instance->schedules as $schedule) {
            $this->schedules->remove($schedule, cascade: true);
        }
    }

    public function restore(AppInstance $instance): void
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
        AppInstance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            $process->update([
                'working_directory' => $this->relocatedPath($process->working_directory, $sourcePath, $workingDirectory),
            ]);
        }

        foreach ($instance->schedules as $schedule) {
            $schedule->update(['host_node_id' => $destination->id]);
        }
    }

    public function activate(AppInstance $instance): void
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

    public function cleanupSourceArtifacts(AppInstance $instance, Node $sourceNode, string $sourcePath): void
    {
        $instance->loadMissing(['processes', 'schedules']);

        foreach ($instance->processes as $process) {
            if ($this->pathOnSource($process->working_directory, $sourcePath)) {
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
