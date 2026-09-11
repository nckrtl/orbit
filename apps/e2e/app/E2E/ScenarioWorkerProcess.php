<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ScenarioProcessResult;
use Closure;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** One independently running scenario Pest process. */
final class ScenarioWorkerProcess
{
    private ?ScenarioProcessResult $result = null;

    /**
     * @param  Closure(): (ScenarioProcessResult|null)  $poller
     * @param  Closure(int): void  $signaler
     * @param  Closure(): ScenarioProcessResult  $stopper
     */
    public function __construct(
        private readonly Closure $poller,
        private readonly Closure $signaler,
        private readonly Closure $stopper,
    ) {}

    public static function completed(ScenarioProcessResult $result): self
    {
        return new self(
            static fn (): ScenarioProcessResult => $result,
            static function (): void {},
            static fn (): ScenarioProcessResult => $result,
        );
    }

    public static function fromSymfonyProcess(Process $process): self
    {
        $finish = static function () use ($process): ScenarioProcessResult {
            try {
                $exitCode = $process->wait();

                return new ScenarioProcessResult(
                    $exitCode,
                    $process->getOutput().$process->getErrorOutput(),
                );
            } catch (ProcessSignaledException $exception) {
                $output = $process->getOutput().$process->getErrorOutput();
                $separator = $output === '' || str_ends_with($output, "\n") ? '' : "\n";

                return new ScenarioProcessResult(
                    128 + $exception->getSignal(),
                    $output.$separator."Scenario process was terminated by signal {$exception->getSignal()}.\n",
                );
            }
        };

        return new self(
            static fn (): ?ScenarioProcessResult => $process->isRunning() ? null : $finish(),
            static function (int $signal) use ($process): void {
                if ($process->isRunning()) {
                    $process->signal($signal);
                }
            },
            static function () use ($process, $finish): ScenarioProcessResult {
                $process->stop(0, defined('SIGKILL') ? SIGKILL : 9);

                return $finish();
            },
        );
    }

    public function poll(): ?ScenarioProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $result = ($this->poller)();
        $this->result = $result;

        return $this->result;
    }

    public function signal(int $signal): void
    {
        if ($this->result === null) {
            ($this->signaler)($signal);
        }
    }

    public function forceStop(): ScenarioProcessResult
    {
        if ($this->result === null) {
            $result = ($this->stopper)();
            $this->result = $result;
        }

        return $this->result;
    }
}
