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
 * Moves the Gateway scheduler to the new release. The scheduler is the Gateway Node's systemd Process that runs
 * `schedule:work` in the Gateway application directory. The handoff interrupts the running schedule, takes the
 * `tasks:tick` lock so no tick is cut off, stops the unit, clears the schedule's overlap mutexes that the stopped
 * run left behind, and starts the unit again. The new `schedule:work` starts from the stable application path,
 * which now links to the new release.
 */
final readonly class GatewaySchedulerHandoff
{
    /** The cache lock that `tasks:tick` holds while it works, for up to 300 seconds. */
    public const string TickLock = 'orbit:tasks:tick';

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param (Closure(int): void)|null $sleep microseconds */
    public function __construct(
        private ProcessRunner $processes,
        private string $applicationPath,
        private int $tickWaitSeconds = 330,
        private int $startWaitSeconds = 30,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
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
     * @return array{outcome: string, unit: string|null}
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
        $lock = Cache::lock(self::TickLock, $this->tickWaitSeconds);

        try {
            $lock->block($this->tickWaitSeconds);
        } catch (LockTimeoutException) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_scheduler_busy',
                message: "A tasks tick held its lock for more than {$this->tickWaitSeconds} seconds, so the scheduler was not restarted.",
                status: 500,
            );
        }

        try {
            $this->systemctl('stop', $unit);
            Artisan::call('schedule:clear-cache');
            $this->systemctl('start', $unit);
            $this->waitActive($unit);
        } finally {
            $lock->release();
        }

        return ['outcome' => 'restarted', 'unit' => $unit];
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
}
