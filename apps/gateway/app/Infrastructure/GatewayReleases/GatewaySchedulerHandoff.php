<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\AgentView\AgentProcessView;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Moves the Gateway scheduler to the new release without cutting off a scheduled command. The scheduler is the
 * Gateway Node's systemd Process that runs `schedule:work` in the Gateway application directory.
 *
 * 1. `schedule:interrupt` ends the current minute's repeating events, such as `tasks:tick`, after their current run.
 * 2. SIGTERM goes to the `schedule:work` main process only. It stops starting new `schedule:run` processes, waits for
 *    the running ones, and exits. A long command, such as a development deploy, finishes on the release it started on.
 * 3. When it has exited, the unit starts again (or systemd already restarted it). The stable application path now
 *    links to the new release.
 *
 * Every scheduled command ran to its end, so each released its own overlap mutex, and no mutex is cleared. Only when
 * the drain outlasts its limit does the handoff take the `tasks:tick` lock, stop the unit, which ends what still runs,
 * clear the overlap mutexes those commands can no longer release, and start it. The result says which happened.
 */
final readonly class GatewaySchedulerHandoff
{
    /** The cache lock that `tasks:tick` holds while it works, for up to 300 seconds. */
    public const string TickLock = 'orbit:tasks:tick';

    /** The default time the old scheduler gets to finish its running commands. */
    public const int DrainSeconds = 600;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): void */
    private Closure $clearMutexes;

    /** @var Closure(string): list<string> */
    private Closure $members;

    /**
     * @param  (Closure(int): void)|null  $sleep  microseconds
     * @param  (Closure(): void)|null  $clearMutexes  clears the schedule's overlap mutexes
     * @param  (Closure(string): list<string>)|null  $members  the command lines of the processes in a unit, main process included
     */
    public function __construct(
        private ProcessRunner $processes,
        private string $applicationPath,
        private int $tickWaitSeconds = 330,
        private int $startWaitSeconds = 30,
        ?Closure $sleep = null,
        private int $drainSeconds = self::DrainSeconds,
        ?Closure $clearMutexes = null,
        ?Closure $members = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clearMutexes = $clearMutexes ?? static function (): void {
            Artisan::call('schedule:clear-cache');
        };
        $this->members = $members ?? $this->unitMembers(...);
    }

    /** The Process that runs the Gateway scheduler, or null when this Gateway has none. */
    public function process(Node $gateway): ?Process
    {
        $application = rtrim($this->applicationPath, '/');

        return Process::query()
            ->whereMorphedTo('owner', $gateway)
            ->orderBy('id')
            ->get()
            ->first(static function (Process $process) use ($application): bool {
                $command = $process->runtime_config['command'] ?? null;

                return $process->runtime === ProcessRuntime::Systemd
                    && rtrim($process->working_directory, '/') === $application
                    && is_array($command)
                    && in_array('schedule:work', $command, true);
            });
    }

    /**
     * @return array{outcome: string, unit: string|null, drain?: array{outcome: string, waited_ms: int, running: list<string>, stopped?: list<string>}}
     *
     * @throws GatewayReleaseException
     */
    public function handoff(Node $gateway): array
    {
        $process = $this->process($gateway);
        $name = $process instanceof Process ? AgentProcessView::unitName($process) : null;

        if ($name === null) {
            return ['outcome' => 'not_found', 'unit' => null];
        }

        $unit = $name.'.service';
        Artisan::call('schedule:interrupt');
        $drain = $this->drain($unit);

        if ($drain['outcome'] === 'forced') {
            $this->forceRestart($unit);
        } else {
            $this->systemctl('start', $unit);
            $this->waitActive($unit);
        }

        return ['outcome' => 'restarted', 'unit' => $unit, 'drain' => $drain];
    }

    /**
     * Asks the running `schedule:work` to finish and waits for it to exit.
     *
     * @return array{outcome: string, waited_ms: int, running: list<string>, stopped?: list<string>}
     */
    private function drain(string $unit): array
    {
        $startedAt = hrtime(true);
        $main = $this->mainPid($unit);

        if ($main === 0) {
            return ['outcome' => 'not_running', 'waited_ms' => 0, 'running' => []];
        }

        $running = $this->others($unit);
        $signal = $this->processes->run(new ProcessInvocation(['sudo', 'systemctl', 'kill', '--kill-whom=main', '--signal=SIGTERM', $unit], timeout: 30.0));

        if (! $signal->succeeded()) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_scheduler_failed',
                message: "The scheduler unit [{$unit}] could not be asked to finish.",
                status: 500,
                result: $signal,
            );
        }

        $deadline = $startedAt + $this->drainSeconds * 1_000_000_000;

        while ($this->mainPid($unit) === $main) {
            if (hrtime(true) >= $deadline) {
                return [
                    'outcome' => 'forced',
                    'waited_ms' => $this->elapsed($startedAt),
                    'running' => $running,
                    'stopped' => $this->others($unit),
                ];
            }

            ($this->sleep)(500_000);
        }

        return ['outcome' => 'drained', 'waited_ms' => $this->elapsed($startedAt), 'running' => $running];
    }

    /**
     * The drain outlasted its limit. Stopping the unit ends the commands that still run, so it waits for a running
     * tick first and then clears the overlap mutexes those commands can no longer release.
     */
    private function forceRestart(string $unit): void
    {
        $lock = Cache::lock(self::TickLock, $this->tickWaitSeconds);

        try {
            $lock->block($this->tickWaitSeconds);
        } catch (LockTimeoutException) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_scheduler_busy',
                message: "A tasks tick held its lock for more than {$this->tickWaitSeconds} seconds, so the scheduler was not stopped. It finishes its running commands and exits on its own.",
                status: 500,
            );
        }

        try {
            $this->systemctl('stop', $unit);
            ($this->clearMutexes)();
            $this->systemctl('start', $unit);
            $this->waitActive($unit);
        } finally {
            $lock->release();
        }
    }

    private function mainPid(string $unit): int
    {
        $result = $this->processes->run(new ProcessInvocation(['systemctl', 'show', '-p', 'MainPID', '--value', $unit], timeout: 10.0));

        return $result->succeeded() ? (int) trim($result->stdout) : 0;
    }

    /**
     * The command lines in the unit other than `schedule:work` itself, for the release record.
     *
     * @return list<string>
     */
    private function others(string $unit): array
    {
        return array_values(array_filter(
            ($this->members)($unit),
            static fn (string $command): bool => ! str_contains($command, 'schedule:work'),
        ));
    }

    /**
     * Reads the unit's control group. Every process `schedule:work` started, and every process those started, is in it.
     *
     * @return list<string>
     */
    private function unitMembers(string $unit): array
    {
        $group = $this->processes->run(new ProcessInvocation(['systemctl', 'show', '-p', 'ControlGroup', '--value', $unit], timeout: 10.0));
        $path = trim($group->stdout);

        if (! $group->succeeded() || $path === '' || str_contains($path, '..')) {
            return [];
        }

        $pids = @file('/sys/fs/cgroup'.$path.'/cgroup.procs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $commands = [];

        foreach ($pids === false ? [] : $pids as $pid) {
            $command = @file_get_contents('/proc/'.(int) $pid.'/cmdline');

            if (is_string($command) && $command !== '') {
                $commands[] = mb_substr(trim(str_replace("\0", ' ', $command)), 0, 200);
            }
        }

        return array_slice($commands, 0, 20);
    }

    private function systemctl(string $verb, string $unit): void
    {
        $result = $this->processes->run(new ProcessInvocation(['sudo', 'systemctl', $verb, $unit], timeout: 120.0));

        if (! $result->succeeded()) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_scheduler_failed',
                message: "The scheduler unit [{$unit}] did not {$verb}.",
                status: 500,
                result: $result,
            );
        }
    }

    private function waitActive(string $unit): void
    {
        $deadline = hrtime(true) + $this->startWaitSeconds * 1_000_000_000;

        do {
            if ($this->processes->run(new ProcessInvocation(['systemctl', 'is-active', '--quiet', $unit], timeout: 10.0))->succeeded()) {
                return;
            }

            ($this->sleep)(500_000);
        } while (hrtime(true) < $deadline);

        throw new GatewayReleaseException(
            step: 'handoff',
            errorCode: 'gateway.release_scheduler_failed',
            message: "The scheduler unit [{$unit}] is not active {$this->startWaitSeconds} seconds after its start.",
            status: 500,
        );
    }

    private function elapsed(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
