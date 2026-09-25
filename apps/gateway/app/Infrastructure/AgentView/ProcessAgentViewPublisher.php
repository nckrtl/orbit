<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs `orbit:agent-view-publish` as child processes and never waits for them (ADR 0151). Task
 * workspaces and Process usage have separate lanes, so a slow Prometheus never delays a commit's
 * notice. Each lane runs one child at a time. A run that passes `DeadlineSeconds` is stopped.
 *
 * Work is never lost. When a workspace run fails, is stopped, or cannot start, its workspaces go
 * back into the queue and run again after `BackoffSeconds`; the child reads the current view, so a
 * retry is safe. A usage sample is not retried, because the next one replaces it.
 */
final class ProcessAgentViewPublisher implements AgentViewPublisher
{
    public const float DeadlineSeconds = 12.0;

    public const float BackoffSeconds = 15.0;

    /** @var array<string, true> `{node}:{instance}` pairs waiting for a run. */
    private array $workspaces = [];

    /** @var array<string, true> The pairs of the running workspace run. */
    private array $inFlight = [];

    private ?int $usage = null;

    /** @var array<string, array{process: Process, startedAt: float}> Running children by lane. */
    private array $running = [];

    /** @var array<string, float> The earliest next start by lane. */
    private array $notBefore = [];

    /**
     * @param  list<string>  $command  The command line before the options.
     * @param  Closure(): float  $clock
     */
    public function __construct(
        private readonly array $command,
        private readonly LoggerInterface $log,
        private readonly Closure $clock,
        private readonly float $deadlineSeconds = self::DeadlineSeconds,
    ) {}

    /** @param list<int> $instanceIds */
    public function queueWorkspaces(int $nodeId, array $instanceIds): void
    {
        foreach ($instanceIds as $instanceId) {
            $this->workspaces["{$nodeId}:{$instanceId}"] = true;
        }
    }

    public function queueUsage(int $sampledAt): void
    {
        $this->usage = $sampledAt;
    }

    public function poll(): void
    {
        foreach (['workspaces', 'usage'] as $lane) {
            $this->reap($lane);
        }

        if ($this->workspaces !== [] && $this->ready('workspaces')) {
            $this->inFlight = $this->workspaces;
            $this->workspaces = [];
            $this->start('workspaces', array_map(static fn (string $pair): string => '--workspace='.$pair, array_keys($this->inFlight)));
        }

        if ($this->usage !== null && $this->ready('usage')) {
            $sampledAt = $this->usage;
            $this->usage = null;
            $this->start('usage', ['--usage='.$sampledAt]);
        }
    }

    public function stop(): void
    {
        foreach ($this->running as $run) {
            $run['process']->stop(0);
        }

        $this->running = [];
    }

    /** Whether a publish run is in progress in any lane. */
    public function isRunning(): bool
    {
        return array_any($this->running, fn ($run) => $run['process']->isRunning());
    }

    /** @return list<string> The `{node}:{instance}` pairs waiting for a run. */
    public function pendingWorkspaces(): array
    {
        return array_keys($this->workspaces);
    }

    private function ready(string $lane): bool
    {
        return ! isset($this->running[$lane]) && $this->now() >= ($this->notBefore[$lane] ?? 0.0);
    }

    private function reap(string $lane): void
    {
        $run = $this->running[$lane] ?? null;

        if ($run === null) {
            return;
        }

        $process = $run['process'];

        if ($process->isRunning()) {
            if ($this->now() - $run['startedAt'] >= $this->deadlineSeconds) {
                $process->stop(0);
                $this->log->warning('The agent view publish run passed its deadline and was stopped.', ['lane' => $lane]);
                $this->failed($lane);
            }

            return;
        }

        if (! $process->isSuccessful()) {
            $this->log->warning('The agent view publish run failed.', [
                'lane' => $lane,
                'exit_code' => $process->getExitCode(),
                'error' => mb_substr(trim($process->getErrorOutput().' '.$process->getOutput()), -500),
            ]);
            $this->failed($lane);

            return;
        }

        unset($this->running[$lane]);

        if ($lane === 'workspaces') {
            $this->inFlight = [];
        }
    }

    /** Puts a failed workspace run's work back and delays the lane's next start. */
    private function failed(string $lane): void
    {
        unset($this->running[$lane]);
        $this->notBefore[$lane] = $this->now() + self::BackoffSeconds;

        if ($lane === 'workspaces') {
            $this->workspaces += $this->inFlight;
            $this->inFlight = [];
        }
    }

    /** @param list<string> $arguments */
    private function start(string $lane, array $arguments): void
    {
        try {
            $process = new Process([...$this->command, ...$arguments]);
            $process->setTimeout(null);
            $process->start();
            $this->running[$lane] = ['process' => $process, 'startedAt' => $this->now()];
        } catch (Throwable $exception) {
            $this->log->warning('The agent view publish run could not start.', ['lane' => $lane, 'error' => $exception->getMessage()]);
            $this->failed($lane);
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
