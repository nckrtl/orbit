<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs `orbit:agent-view-publish` as child processes and never waits for them (ADR 0151). Task
 * workspaces, Process usage, and live log lines have separate lanes, so a slow Prometheus never
 * delays a commit's notice and a slow Reverb never delays the agent view. Each lane runs one child at
 * a time. A run that passes `DeadlineSeconds` is stopped.
 *
 * When a workspace run fails, is stopped, or cannot start, its workspaces go back into the queue and
 * run again after `BackoffSeconds`; the child reads the current view, so a retry is safe. A workspace
 * that fails `MaxAttempts` runs in a row is dropped with an error in the log, until the agent reports
 * a new change for it. A usage sample is not retried, because the next one replaces it.
 *
 * The log lane (ADR 0153) takes its work from {@see LogRelayQueue}, which keeps the events in order,
 * and passes each batch as JSON on the child's standard input. A failed log run puts its batch back
 * and runs again after `LogBackoffSeconds`.
 */
final class ProcessAgentViewPublisher implements AgentViewPublisher
{
    public const float DeadlineSeconds = 12.0;

    public const float BackoffSeconds = 15.0;

    public const int MaxAttempts = 5;

    public const float LogBackoffSeconds = 2.0;

    /** @var array<string, int> Failed runs in a row, by `{node}:{instance}` pair. */
    private array $failures = [];

    /** @var array<string, true> `{node}:{instance}` pairs waiting for a run. */
    private array $workspaces = [];

    /** @var array<string, true> The pairs of the running workspace run. */
    private array $inFlight = [];

    private ?int $usage = null;

    private readonly LogRelayQueue $logs;

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
        private readonly ?string $workingDirectory = null,
        ?LogRelayQueue $logs = null,
    ) {
        $this->logs = $logs ?? new LogRelayQueue($clock);
    }

    /** @param list<int> $instanceIds */
    public function queueWorkspaces(int $nodeId, array $instanceIds): void
    {
        foreach ($instanceIds as $instanceId) {
            // A new change is new work: it gets a full set of attempts again.
            unset($this->failures["{$nodeId}:{$instanceId}"]);
            $this->workspaces["{$nodeId}:{$instanceId}"] = true;
        }
    }

    public function queueUsage(int $sampledAt): void
    {
        $this->usage = $sampledAt;
    }

    /** @param array<string, mixed> $data */
    public function queueLog(int $nodeId, string $event, array $data): void
    {
        match ($event) {
            'client-log' => $this->logs->lines($nodeId, $data),
            'client-log-end' => $this->logs->end($nodeId, $data),
            default => null,
        };
    }

    public function queueLogAgentLeft(int $nodeId): void
    {
        $this->logs->agentLeft($nodeId);
    }

    public function logStreamsChanged(): void
    {
        $this->logs->streamsChanged();
    }

    public function poll(): void
    {
        foreach (['workspaces', 'usage', 'logs'] as $lane) {
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

        if ($this->ready('logs')) {
            $batch = $this->logs->take();

            if ($batch !== null) {
                $this->start('logs', ['--logs'], (string) json_encode($batch, JSON_INVALID_UTF8_SUBSTITUTE));
            }
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
            $this->failures = array_diff_key($this->failures, $this->inFlight);
            $this->inFlight = [];
        }

        if ($lane === 'logs') {
            $this->logs->succeeded(self::openStreams($process->getOutput()));
        }
    }

    /** The `open_streams` count a log run printed on its last line, or null. */
    private static function openStreams(string $output): ?int
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $result = json_decode((string) end($lines), associative: true);

        return is_array($result) && is_int($result['open_streams'] ?? null) ? $result['open_streams'] : null;
    }

    /** Puts a failed workspace run's work back and delays the lane's next start. */
    private function failed(string $lane): void
    {
        unset($this->running[$lane]);
        $this->notBefore[$lane] = $this->now() + ($lane === 'logs' ? self::LogBackoffSeconds : self::BackoffSeconds);

        if ($lane === 'logs') {
            $this->logs->failed();

            return;
        }

        if ($lane !== 'workspaces') {
            return;
        }

        $dropped = [];

        foreach (array_keys($this->inFlight) as $pair) {
            $this->failures[$pair] = ($this->failures[$pair] ?? 0) + 1;

            if ($this->failures[$pair] >= self::MaxAttempts) {
                $dropped[] = $pair;
                unset($this->failures[$pair]);

                continue;
            }

            $this->workspaces[$pair] = true;
        }

        $this->inFlight = [];

        if ($dropped !== []) {
            $this->log->error('The agent view publish run gave up on task workspaces after repeated failures; their groups keep their stored counts until the next change.', [
                'workspaces' => $dropped,
                'attempts' => self::MaxAttempts,
            ]);
        }
    }

    /** @return array<string, int> Failed runs in a row, by pair still queued. */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @param list<string> $arguments */
    private function start(string $lane, array $arguments, ?string $input = null): void
    {
        try {
            $process = new Process([...$this->command, ...$arguments], $this->workingDirectory);
            $process->setTimeout(null);
            $process->setInput($input);
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
