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
            ? $this->renderDeploymentStreamMachine($stream, $openingPhase)
            : $this->renderDeploymentStreamHuman($stream, $title, $openingPhase, $verb);
    }

    private function renderDeploymentStreamMachine(DeploymentStream $stream, string $openingPhase): int
    {
        $currentOrder = null;
        $seenStepIds = [];

        try {
            foreach ($stream as $event) {
                $this->requestId = $event->requestId;

                if ($event instanceof DeploymentPhaseEvent) {
                    $stepId = $event->stepName !== null ? "{$event->phase}:{$event->stepName}" : $event->phase;
                    $order = $this->deploymentPhaseOrder($event->phase);

                    // Human and JSON must agree on what a valid stream looks like: a wrong
                    // opening phase, an out-of-order phase, or a duplicate phase (F6).
                    if (($currentOrder === null && $event->phase !== $openingPhase)
                        || ($currentOrder !== null && ($order < $currentOrder || isset($seenStepIds[$stepId])))) {
                        throw new GatewayApiException(
                            'Gateway deployment stream is invalid.', 'deployment.stream_invalid', requestId: $event->requestId,
                        );
                    }

                    $seenStepIds[$stepId] = true;
                    $currentOrder = $order;
                    $this->renderPhaseJson($event);

                    continue;
                }

                if ($event instanceof DeploymentOutputEvent) {
                    if ($currentOrder === null) {
                        throw new GatewayApiException(
                            'Gateway deployment stream is invalid.', 'deployment.stream_invalid', requestId: $event->requestId,
                        );
                    }

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
        $currentOrder = $this->deploymentPhaseOrder($openingPhase);
        $event = null;

        try {
            $event = $progress->during($currentStepId, function () use ($iterator, $openingPhase): ?DeploymentEvent {
                $iterator->rewind();

                if (! $iterator->valid()) {
                    return null;
                }

                $first = $iterator->current();
                $this->requestId = $first->requestId;

                if ($first instanceof DeploymentResultEvent) {
                    return $first;
                }

                if (! $first instanceof DeploymentPhaseEvent || $first->phase !== $openingPhase) {
                    throw new GatewayApiException(
                        'Gateway deployment stream is invalid.', 'deployment.stream_invalid', requestId: $first->requestId,
                    );
                }

                $iterator->next();
                $this->renderHumanOutputEvents($iterator);

                return $iterator->valid() ? $iterator->current() : null;
            });

            while ($event instanceof DeploymentPhaseEvent) {
                $this->requestId = $event->requestId;
                $nextPhase = $event->phase;
                $nextOrder = $this->deploymentPhaseOrder($nextPhase);
                [$nextStepId, $waiting, $running, $completed] = $this->deploymentPhaseLabels($nextPhase, $event->stepName);

                // An out-of-order or duplicate phase is SDK-valid but not something the
                // Gateway can send today; the row that was current takes the blame, the same
                // way an unmapped failed_step boundary does in settleDeploymentTree(). Settle
                // the tree explicitly here: this throw happens outside any during() scope, so
                // there is no automatic settle-on-exception to rely on this time.
                if ($nextOrder < $currentOrder || isset($seenStepIds[$nextStepId])) {
                    $progress->complete($currentStepId, ProgressState::Failure, 'Gateway deployment stream is invalid.');
                    $progress->finish("{$verb} failed.");

                    return $this->renderStreamFailure(
                        'deployment.stream_invalid', 'Gateway deployment stream is invalid.', $event->requestId,
                    );
                }

                $seenStepIds[$nextStepId] = true;
                $currentOrder = $nextOrder;
                $progress->complete($currentStepId, ProgressState::Success);

                if (in_array($nextPhase, $alwaysEmitted, true)) {
                    // Already admitted up front; nothing to reveal.
                } elseif ($nextPhase === 'before_activation' && $activationStepId !== null) {
                    $progress->admitBefore($activationStepId, $nextStepId, $waiting, $running, $completed);
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
            foreach ($alwaysEmitted as $phase) {
                if ($this->deploymentPhaseOrder($phase) > $currentOrder) {
                    $progress->complete($this->deploymentPhaseLabels($phase, null)[0], ProgressState::Skipped, 'Not reached.');
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
                $progress->complete($this->deploymentPhaseLabels($phase, null)[0], ProgressState::Skipped, 'Not reached.');
            }
        }
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
