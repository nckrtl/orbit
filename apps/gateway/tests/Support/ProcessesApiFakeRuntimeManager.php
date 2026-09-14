<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Models\Process;
use RuntimeException;
use SensitiveParameter;

final class ProcessesApiFakeRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<int> */
    public array $started = [];

    /** @var list<int> */
    public array $stopped = [];

    /** @var list<int> */
    public array $restarted = [];

    /** @var list<int> */
    public array $convergedProcessIds = [];

    /** @var list<int> */
    public array $logLines = [];

    public string $logs = '';

    public ?ProcessOperationException $startFailure = null;

    public bool $failConverge = false;

    public bool $failStartDuringCall = false;

    public ?ProcessOperationException $lastConvergeFailure = null;

    public ?string $statusOverride = null;

    public function assertCanStart(Process $process): void {}

    public function converge(#[SensitiveParameter] Process $process): void
    {
        $this->convergedProcessIds[] = (int) $process->getKey();

        if ($this->failConverge) {
            $this->lastConvergeFailure = new ProcessOperationException(
                step: 'create-container',
                errorCode: 'process.docker_converge_failed',
                message: 'Docker convergence failed.',
                previous: new RuntimeException('Runtime adapter failed.'),
            );

            throw $this->lastConvergeFailure;
        }
    }

    public function start(#[SensitiveParameter] Process $process): void
    {
        if ($this->failStartDuringCall) {
            throw new ProcessOperationException(
                step: 'start',
                errorCode: 'process.start_failed',
                message: 'The process did not start.',
            );
        }

        if ($this->startFailure instanceof ProcessOperationException) {
            throw $this->startFailure;
        }

        $this->started[] = $process->id;
    }

    public function stop(Process $process): void
    {
        $this->stopped[] = $process->id;
    }

    public function restart(Process $process): void
    {
        $this->restarted[] = $process->id;
    }

    public function remove(Process $process): void {}

    public function status(Process $process): string
    {
        if (is_string($this->statusOverride)) {
            return $this->statusOverride;
        }

        return $process->exists
            ? $process->desired_state->value
            : 'absent';
    }

    public function logs(Process $process, int $lines): string
    {
        $this->logLines[] = $lines;

        return $this->logs;
    }
}
