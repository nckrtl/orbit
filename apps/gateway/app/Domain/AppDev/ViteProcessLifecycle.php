<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Processes\ProcessOperationException;
use App\Models\Instance;
use App\Models\Process;
use Closure;

final readonly class ViteProcessLifecycle
{
    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(): void */
    private Closure $wait;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(): void)|null  $wait
     */
    public function __construct(private AppDevSourceOperationLock $owner, private VitePortAllocator $ports, private VitePortRuntime $runtime, ?Closure $clock = null, ?Closure $wait = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->wait = $wait ?? static function (): void {
            usleep(250_000);
        };
    }

    /**
     * @param  Closure(): void  $launch
     * @param  Closure(): void  $stop
     */
    public function run(Process $process, Closure $launch, Closure $stop, bool $start, bool $restart = false, bool $explicitStart = false): void
    {
        $instance = Instance::query()->with('node')->findOrFail($process->owner_id);
        $this->owner->synchronized($instance->node_id, function () use ($process, $instance, $launch, $stop, $start, $restart, $explicitStart): void {
            try {
                $port = $this->ports->assign($instance, app: $process->app);
                if ($port === null) {
                    throw new ProcessOperationException('vite-prepare', 'vite.development_required', 'The Vite preset requires a development Instance.');
                }
                $owned = $this->runtime->ownsListener($process, $instance, $port);
                if ($owned && $start && ! $restart && $this->runtime->ready($process, $instance, $port)) {
                    if ($explicitStart) {
                        $this->runtime->markAwake($instance);
                    }

                    return;
                }

                $this->runtime->suspendTraffic($instance);
                if ($restart) {
                    $stop();
                    $owned = false;
                }
                $deadline = ($this->clock)() + 50;
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    if (! $owned) {
                        $port = $this->ports->assign($instance, recheck: true, app: $process->app);
                    }
                    $instance->refresh();
                    $this->runtime->prepare($process, $instance);
                    $this->runtime->project($instance);
                    $launch();
                    if (! $start) {
                        return;
                    }

                    $attemptDeadline = min($deadline, ($this->clock)() + 15);
                    do {
                        if ($this->runtime->ready($process, $instance, (int) $port)) {
                            if ($explicitStart && ! $restart) {
                                $this->runtime->markAwake($instance);
                            }

                            return;
                        }
                        ($this->wait)();
                    } while (($this->clock)() < $attemptDeadline);

                    $owned = $this->runtime->ownsListener($process, $instance, (int) $port);
                    if ($owned || ($this->clock)() >= $deadline) {
                        break;
                    }
                    $stop();
                    $replacement = $this->ports->assign($instance, recheck: true, app: $process->app);
                    if ($replacement === $port) {
                        break;
                    }
                }

                $stop();
                throw new ProcessOperationException('vite-readiness', 'vite.not_ready', 'The owned Vite endpoint did not become ready. Check the Process logs and retry.');
            } catch (RuntimeConvergenceException $exception) {
                throw new ProcessOperationException($exception->step, $exception->errorCode, $exception->getMessage(), previous: $exception);
            }
        });
    }
}
