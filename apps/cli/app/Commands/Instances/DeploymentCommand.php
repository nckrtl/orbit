<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
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
        $event = null;

        try {
            $event = $progress->during($currentStepId, function () use ($iterator, $openingPhase): ?DeploymentEvent {
                $iterator->rewind();

                if (! $iterator->valid()) {
                    return null;
                }

                $first = $iterator->current();

                if ($first instanceof DeploymentResultEvent) {
                    return $first;
                }

                if (! $first instanceof DeploymentPhaseEvent || $first->phase !== $openingPhase) {
                    throw new GatewayApiException('Gateway deployment stream is invalid.', 'deployment.stream_invalid');
                }

                $iterator->next();
                $this->renderHumanOutputEvents($iterator);

                return $iterator->valid() ? $iterator->current() : null;
            });

            while ($event instanceof DeploymentPhaseEvent) {
                $this->requestId = $event->requestId;
                $nextPhase = $event->phase;
                [$nextStepId, $waiting, $running, $completed] = $this->deploymentPhaseLabels($nextPhase, $event->stepName);
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
        if ($event->succeeded()) {
            $progress->complete($currentStepId, ProgressState::Success);

            return;
        }

        $failurePhase = $event->failedStep !== null ? $this->deploymentPhaseForFailedStep($event->failedStep) : null;

        $progress->complete(
            $currentStepId,
            $failurePhase === $currentPhase ? ProgressState::Failure : ProgressState::Skipped,
            $failurePhase === $currentPhase ? ($event->errorCode ?? '') : 'Not reached.',
        );

        $currentOrder = $this->deploymentPhaseOrder($currentPhase);

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
            $this->writeHumanMessage("{$event->stream}: ".$this->terminalValue($event->data));
            $iterator->next();
        }
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

    /** Maps a result's failed_step boundary back to the phase vocabulary, or null for a boundary with no phase. */
    private function deploymentPhaseForFailedStep(string $failedStep): ?string
    {
        return match ($failedStep) {
            'preparation' => 'source_preparation',
            'environment' => 'environment_sync',
            'before_activation' => 'before_activation',
            'activation' => 'activation',
            'cache_refresh' => 'php_refresh',
            'after_activation' => 'after_activation',
            'rollback_selection' => 'rollback',
            default => null,
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
