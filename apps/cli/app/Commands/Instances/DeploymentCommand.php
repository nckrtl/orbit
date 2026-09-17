<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Support\Console\Animation;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalText;
use Generator;
use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Deployments\DeploymentEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentOutputEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentPhaseEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentResultEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Throwable;

abstract class DeploymentCommand extends GatewayCommand
{
    private ?string $requestId = null;

    /** $openingPhase is the first phase the Gateway always emits for this stream kind. */
    protected function renderDeploymentStream(DeploymentStream $stream, string $title, string $openingPhase, string $verb): int
    {
        $this->requestId = null;
        // A signal delivered while the SDK's read_timeout poll is blocked in fread() can
        // record its interrupt intent without its own throw reliably unwinding out of that
        // call (a real PHP limitation, not hypothetical). Re-check between polls instead of
        // relying on that throw alone; a no-op when nothing is pending, e.g. --json, which
        // installs no signal handler at all.
        $stream->onIdle(InterruptIntent::throwIfPending(...));

        return $this->option('json') === true
            ? $this->renderDeploymentStreamMachine($stream)
            : $this->renderDeploymentStreamHuman($stream, $title, $openingPhase, $verb);
    }

    private function renderDeploymentStreamMachine(DeploymentStream $stream): int
    {
        try {
            foreach ($stream as $event) {
                $this->requestId = $event->requestId;

                if ($event instanceof DeploymentPhaseEvent) {
                    $this->renderPhaseJson($event);

                    continue;
                }

                if ($event instanceof DeploymentOutputEvent) {
                    $this->renderOutputJson($event);

                    continue;
                }

                if ($event instanceof DeploymentResultEvent) {
                    $this->renderResultJson($event);

                    return $event->succeeded() ? self::SUCCESS : self::FAILURE;
                }
            }
        } catch (GatewayApiException $exception) {
            return $this->renderStreamFailure(
                $exception->errorCode() ?? 'deployment.stream_invalid',
                $exception->getMessage(),
                $exception->requestId() ?? $this->requestId,
            );
        } catch (Throwable) {
            return $this->renderStreamFailure(
                'deployment.stream_failed',
                'Deployment stream failed.',
                $this->requestId,
            );
        } finally {
            $stream->close();
        }

        return $this->renderStreamFailure(
            'deployment.stream_invalid',
            'Gateway deployment stream is invalid.',
            $this->requestId,
        );
    }

    private function renderDeploymentStreamHuman(DeploymentStream $stream, string $title, string $openingPhase, string $verb): int
    {
        /** @var Generator<int, DeploymentEvent> $iterator */
        $iterator = $stream->getIterator();
        $progress = $this->progressDisplay($title);
        $alwaysEmitted = $this->alwaysEmittedPhases($openingPhase);

        foreach ($alwaysEmitted as $phase) {
            [$stepId, $waiting, $running, $completed] = $this->deploymentPhaseLabels($phase, null);
            $progress->admit($stepId, $waiting, $running, $completed);
        }

        $activationStepId = in_array('activation', $alwaysEmitted, true)
            ? $this->deploymentPhaseLabels('activation', null)[0]
            : null;
        $currentStepId = $this->deploymentPhaseLabels($openingPhase, null)[0];
        $currentPhase = $openingPhase;
        $seenStepIds = [$currentStepId => true];
        $event = null;

        try {
            $event = $progress->during($currentStepId, function () use ($iterator): ?DeploymentEvent {
                $iterator->rewind();

                if (! $iterator->valid()) {
                    return null;
                }

                $this->requestId = $iterator->current()->requestId;
                // Render whatever arrives rather than rejecting a wrong opening phase or an
                // output-first stream: this is a documented best-effort tree, not a strict
                // protocol check (F6). A genuinely malformed sequence (a literal duplicate)
                // still surfaces, via ProgressDisplay's own guards below, as a settled tree.
                $this->renderHumanOutputEvents($iterator);

                if (! $iterator->valid() || $iterator->current() instanceof DeploymentResultEvent) {
                    return $iterator->valid() ? $iterator->current() : null;
                }

                $iterator->next();
                $this->renderHumanOutputEvents($iterator);

                return $iterator->valid() ? $iterator->current() : null;
            });

            while ($event instanceof DeploymentPhaseEvent) {
                $this->requestId = $event->requestId;
                $nextPhase = $event->phase;
                [$nextStepId, $waiting, $running, $completed] = $this->deploymentPhaseLabels($nextPhase, $event->stepName);

                // A literal duplicate (the same step named twice) cannot be re-admitted or
                // re-started; ProgressDisplay::during() below would throw a LogicException
                // once this row is reused while already terminal. Human and JSON must still
                // agree on the exit status for this SDK-valid stream (F6): settle the tree
                // neutrally and fall through to plain lines instead of failing outright, since
                // JSON keeps reading and reports whatever the eventual result says.
                if (isset($seenStepIds[$nextStepId])) {
                    return $this->degradeDeploymentStream($progress, $alwaysEmitted, $currentStepId, $iterator, $event, $verb);
                }

                $seenStepIds[$nextStepId] = true;
                $progress->complete($currentStepId, ProgressState::Success);

                if (in_array($nextPhase, $alwaysEmitted, true)) {
                    // Already admitted up front; nothing to reveal.
                } elseif ($nextPhase === 'before_activation' && $activationStepId !== null) {
                    try {
                        $progress->admitBefore($activationStepId, $nextStepId, $waiting, $running, $completed);
                    } catch (LogicException) {
                        // The activation anchor already settled (before_activation arrived
                        // after activation — SDK-valid, though the Gateway never emits this
                        // order live): admitBefore() cannot position the row ahead of a row
                        // that is no longer waiting. Append it instead of failing outright, so
                        // human and JSON agree on the eventual outcome (R2), the same way a
                        // literal duplicate degrades rather than throws.
                        $progress->admit($nextStepId, $waiting, $running, $completed);
                    }
                } else {
                    $progress->admit($nextStepId, $waiting, $running, $completed);
                }

                $currentStepId = $nextStepId;
                $currentPhase = $nextPhase;

                $event = $progress->during($currentStepId, function () use ($iterator): ?DeploymentEvent {
                    $iterator->next();
                    $this->renderHumanOutputEvents($iterator);

                    return $iterator->valid() ? $iterator->current() : null;
                });
            }
        } catch (Throwable $exception) {
            // The progress tree already settled with its own "Operation interrupted."
            // footer (ProgressDisplay::during()'s own exception handling). Printing a
            // second, generic failure on top of that would be misleading.
            if (InterruptIntent::cancellation($exception)) {
                return self::FAILURE;
            }

            if ($exception instanceof GatewayApiException) {
                return $this->renderStreamFailure(
                    $exception->errorCode() ?? 'deployment.stream_invalid',
                    $exception->getMessage(),
                    $exception->requestId() ?? $this->requestId,
                );
            }

            return $this->renderStreamFailure(
                'deployment.stream_failed',
                'Deployment stream failed.',
                $this->requestId,
            );
        } finally {
            $stream->close();
        }

        if ($event instanceof DeploymentResultEvent) {
            $this->requestId = $event->requestId;
            $this->settleDeploymentTree($progress, $alwaysEmitted, $currentStepId, $currentPhase, $event);
            $progress->finish($event->succeeded() ? "{$verb} succeeded." : "{$verb} failed.");
            $this->writeDeploymentResultSummary($event);

            return $event->succeeded() ? self::SUCCESS : self::FAILURE;
        }

        $progress->complete($currentStepId, ProgressState::Failure, 'Gateway deployment stream is invalid.');
        $progress->finish("{$verb} failed.");

        return $this->renderStreamFailure(
            'deployment.stream_invalid',
            'Gateway deployment stream is invalid.',
            $this->requestId,
        );
    }

    /** @param list<string> $alwaysEmitted */
    private function settleDeploymentTree(
        ProgressDisplay $progress,
        array $alwaysEmitted,
        string $currentStepId,
        string $currentPhase,
        DeploymentResultEvent $event,
    ): void {
        $currentOrder = $this->deploymentPhaseOrder($currentPhase);

        if ($event->succeeded()) {
            $progress->complete($currentStepId, ProgressState::Success);

            // An SDK-valid but currently Gateway-impossible stream (a succeeded result before
            // every always-emitted phase fired) would otherwise leave those rows Waiting, and
            // finish() cannot settle with unresolved work still admitted (F6). complete() only
            // allows a Waiting row to become Skipped, never Success, so this reuses the same
            // "not reached" treatment as the failure path below, regardless of the outcome.
            // Phase order is only a heuristic for "later" here: rendering a best-effort tree
            // (F6) can settle a higher-order phase before a lower-order one arrives, so a row
            // this loop expects to still be Waiting may already be terminal; skip it quietly.
            foreach ($alwaysEmitted as $phase) {
                if ($this->deploymentPhaseOrder($phase) > $currentOrder) {
                    try {
                        $progress->complete($this->deploymentPhaseLabels($phase, null)[0], ProgressState::Skipped, 'Not reached.');
                    } catch (LogicException) {
                        continue;
                    }
                }
            }

            return;
        }

        // The row that was current when the stream ended is always the one to blame, whether
        // or not the Gateway's failed_step boundary has a row of its own: rollback's
        // activation/cache_refresh boundaries and a bare "operation" failure never get their
        // own row, and the tree must still show where things broke rather than leaving every
        // reached row looking merely skipped.
        $progress->complete($currentStepId, ProgressState::Failure, $event->errorCode ?? '');

        foreach ($alwaysEmitted as $phase) {
            if ($this->deploymentPhaseOrder($phase) > $currentOrder) {
                try {
                    $progress->complete($this->deploymentPhaseLabels($phase, null)[0], ProgressState::Skipped, 'Not reached.');
                } catch (LogicException) {
                    continue;
                }
            }
        }
    }

    /**
     * A duplicate phase has no row of its own left to blame (the repeated step already
     * settled), and re-admitting or re-starting it would throw. Human and JSON must still agree
     * on the exit status for this SDK-valid stream (F6): settle every row (Warning for the one
     * that was current, since the eventual outcome is still unknown; Skipped for the rest), then
     * fall through to plain lines for whatever the stream sends next, finishing with the same
     * status JSON would report for the same terminal result — not a hard failure.
     *
     * @param  list<string>  $alwaysEmitted
     * @param  Generator<int, DeploymentEvent>  $iterator
     */
    private function degradeDeploymentStream(
        ProgressDisplay $progress,
        array $alwaysEmitted,
        string $currentStepId,
        Generator $iterator,
        DeploymentEvent $event,
        string $verb,
    ): int {
        try {
            $progress->complete($currentStepId, ProgressState::Warning, 'Gateway deployment stream repeated a step.');
        } catch (LogicException) {
            // Already terminal; nothing to settle.
        }

        foreach ($alwaysEmitted as $phase) {
            try {
                $progress->complete($this->deploymentPhaseLabels($phase, null)[0], ProgressState::Skipped, 'Not reached.');
            } catch (LogicException) {
                continue;
            }
        }

        $progress->finish("{$verb} stream diverged; remaining events follow.");

        while (! ($event instanceof DeploymentResultEvent)) {
            if ($event instanceof DeploymentPhaseEvent) {
                $this->requestId = $event->requestId;
                $this->writeHumanMessage('Phase: '.$event->phase.($event->stepName !== null ? ":{$event->stepName}" : ''));
            }

            $iterator->next();
            $this->renderHumanOutputEvents($iterator);

            if (! $iterator->valid()) {
                return $this->renderStreamFailure(
                    'deployment.stream_invalid', 'Gateway deployment stream is invalid.', $this->requestId,
                );
            }

            $event = $iterator->current();
        }

        $this->requestId = $event->requestId;
        $this->writeDeploymentResultSummary($event);

        return $event->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /** @param Generator<int, DeploymentEvent> $iterator */
    private function renderHumanOutputEvents(Generator $iterator): void
    {
        while ($iterator->valid() && $iterator->current() instanceof DeploymentOutputEvent) {
            $event = $iterator->current();
            $this->requestId = $event->requestId;
            $this->writeStreamedMessage("{$event->stream}: ".$this->terminalValue($event->data));
            $iterator->next();
        }
    }

    /**
     * Opt-in to Animation::printLine() (F4/ORB-363): deploy and rollback step output prints
     * above the live progress region without a clear-and-restart cycle. Every other caller of
     * writeHumanMessage() keeps the shared clear-and-restart mechanism untouched.
     */
    private function writeStreamedMessage(string $message): void
    {
        if ($this->consoleMode()->machine) {
            return;
        }

        Animation::printLine($this->output, implode("\n", TerminalText::wrap(
            TerminalText::safe($message),
            $this->consoleMode()->columns,
        ))."\n");
    }

    private function writeDeploymentResultSummary(DeploymentResultEvent $event): void
    {
        if (! $event->succeeded()) {
            if ($event->failedStep !== null) {
                $this->writeHumanMessage('Failed boundary: '.$event->failedStep);
            }

            if ($event->errorCode !== null) {
                $this->writeHumanMessage('Error code: '.$event->errorCode);
            }
        }

        if ($event->selectedRelease !== null) {
            $this->writeHumanMessage('Selected release: '.$event->selectedRelease);
        }

        $this->writeHumanMessage('Request ID: '.$event->requestId);
    }

    protected function terminalValue(string $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function renderPhaseJson(DeploymentPhaseEvent $event): void
    {
        $payload = $this->eventPayload($event);
        $payload['phase'] = $event->phase;

        if ($event->stepName !== null) {
            $payload['step_name'] = $event->stepName;
        }

        $this->writeJson($payload);
    }

    private function renderOutputJson(DeploymentOutputEvent $event): void
    {
        $this->writeJson([
            ...$this->eventPayload($event),
            'stream' => $event->stream,
            'data_base64' => base64_encode($event->data),
        ]);
    }

    private function renderResultJson(DeploymentResultEvent $event): void
    {
        $this->writeJson([
            ...$this->eventPayload($event),
            'status' => $event->status,
            'failed_step' => $event->failedStep,
            'error_code' => $event->errorCode,
            'selected_release' => $event->selectedRelease,
        ]);
    }

    private function renderStreamFailure(string $code, string $message, ?string $requestId): int
    {
        return $this->renderGatewayFailure($code, $message, $requestId);
    }

    /** @return array{type: string, sequence: int, request_id: string} */
    private function eventPayload(DeploymentEvent $event): array
    {
        return [
            'type' => match (true) {
                $event instanceof DeploymentPhaseEvent => 'phase',
                $event instanceof DeploymentOutputEvent => 'output',
                $event instanceof DeploymentResultEvent => 'result',
                default => throw new LogicException('Unsupported deployment event.'),
            },
            'sequence' => $event->sequence,
            'request_id' => $event->requestId,
        ];
    }

    /** @return array{string, string, string, string} Step ID, waiting, running, and completed labels. */
    private function deploymentPhaseLabels(string $phase, ?string $stepName): array
    {
        return match ($phase) {
            'source_preparation' => ['source_preparation', 'Resolve release', 'Resolving release', 'Resolved release'],
            'environment_sync' => ['environment_sync', 'Sync environment', 'Syncing environment', 'Synced environment'],
            'activation' => ['activation', 'Activate release', 'Activating release', 'Activated release'],
            'php_refresh' => ['php_refresh', 'Refresh PHP cache', 'Refreshing PHP cache', 'Refreshed PHP cache'],
            'rollback' => ['rollback', 'Select release', 'Selecting release', 'Selected release'],
            'before_activation', 'after_activation' => $this->deploymentNamedStepLabels($phase, $stepName),
            default => throw new LogicException("Unsupported deployment phase [{$phase}]."),
        };
    }

    /** @return array{string, string, string, string} */
    private function deploymentNamedStepLabels(string $phase, ?string $stepName): array
    {
        if ($stepName === null) {
            throw new LogicException("Deployment phase [{$phase}] requires a step name.");
        }

        return ["{$phase}:{$stepName}", "Run {$stepName}", "Running {$stepName}", "Ran {$stepName}"];
    }

    /** @return list<string> The phases the Gateway always emits, in order, for a stream that opens with $openingPhase. */
    private function alwaysEmittedPhases(string $openingPhase): array
    {
        return match ($openingPhase) {
            'source_preparation' => ['source_preparation', 'environment_sync', 'activation'],
            'rollback' => ['rollback'],
            default => throw new LogicException("Unsupported opening phase [{$openingPhase}]."),
        };
    }

    /** Relative execution order of a phase, used to tell which always-emitted phases were never reached. */
    private function deploymentPhaseOrder(string $phase): float
    {
        return match ($phase) {
            'source_preparation', 'rollback' => 0,
            'environment_sync' => 1,
            'before_activation' => 1.5,
            'activation' => 2,
            'php_refresh', 'after_activation' => 2.5,
            default => 99,
        };
    }
}
