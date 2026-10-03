<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentCommandResult;
use App\Domain\Instances\Deployment\DeploymentDeadline;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentEventCollector;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\Instance;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class DeployDefaultInstanceAction
{
    public function __construct(
        private DevelopmentDeployment $deployment,
        private ProjectDevelopmentDeployStepStore $steps,
        private InstanceEnvironmentOperationLock $operations,
        private CommandDeadline $deadline,
        private InstanceDeploymentRecorder $recorder,
        private DevelopmentRouteProjector $routes,
        private DevelopmentProjectionOperationLock $projections,
    ) {}

    /** Scheduled runs return null for an unchanged commit, without a history row. */
    public function execute(Instance $instance, ?DeploymentRequest $request = null, bool $onlyChanged = false, ?string $triggeredBy = null): ?DeploymentResult
    {
        $request ??= DeploymentRequest::withoutOutput();
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $request, $onlyChanged, $triggeredBy): ?DeploymentResult {
                $instance->refresh()->loadMissing(['project', 'node']);
                $this->assertAvailable($instance);
                $steps = $this->steps->ordered($instance->project);
                $seconds = DeploymentDeadline::InfrastructureSeconds + array_sum(array_map(static fn ($step): int => $step->timeoutSeconds, $steps));

                return $this->deadline->within($seconds, function () use ($instance, $request, $onlyChanged, $triggeredBy, $steps): ?DeploymentResult {
                    $collector = new DeploymentEventCollector;
                    $record = null;
                    $output = new DeploymentRequest(
                        output: static function (DeploymentEvent $event) use ($request, $collector): void {
                            $collector->output($event);
                            $request->emit($event);
                        },
                        cancellation: $request->cancellation,
                        phase: static function (DeploymentProgressPhase $phase, ?string $stepName) use ($request, $collector): void {
                            $collector->phase($phase, $stepName);
                            $request->emitPhase($phase, $stepName);
                        },
                    );
                    $release = null;
                    $selected = null;
                    $commands = [];
                    $boundary = DeploymentFailureBoundary::Preparation;
                    try {
                        $this->assertNotCancelled($output);
                        $output->emitPhase(DeploymentProgressPhase::SourcePreparation);
                        $this->deployment->initialize($instance);
                        $selected = $this->deployment->selected($instance);
                        if (! $instance->development_release_layout) {
                            $instance->update(['development_release_layout' => true]);
                        }
                        // The flag is layout intent, not a receipt of remote convergence. Reconcile
                        // before the unchanged fast path so a lost process cannot strand migration.
                        $this->projectRoute($instance);
                        $commit = $this->deployment->target($instance);
                        if ($onlyChanged && $selected->commit === $commit) {
                            return null;
                        }
                        if ($triggeredBy !== null) {
                            $record = $this->recorder->start($instance, $triggeredBy);
                        }
                        // Remove interrupted candidates before building another; preserve the last two selections.
                        $this->deployment->prune($instance, $selected);
                        $release = $this->deployment->prepare($instance, $commit);
                        $boundary = DeploymentFailureBoundary::BeforeActivation;
                        foreach ($steps as $step) {
                            $this->assertNotCancelled($output);
                            $output->emitPhase(DeploymentProgressPhase::BeforeActivation, $step->name);
                            try {
                                $commands[] = new DeploymentCommandResult($step->name, $this->deployment->executeStep($instance, $release, $step, $output));
                            } catch (RuntimeConvergenceException $exception) {
                                if ($exception->result !== null) {
                                    $commands[] = new DeploymentCommandResult($step->name, $exception->result);
                                }
                                if ($step->required || $exception->result === null || $exception->errorCode !== 'deployment.step_failed') {
                                    throw $exception;
                                }
                                $output->emit(new DeploymentEvent($step->name, DeploymentOutputStream::Stderr, "Best-effort step failed (exit {$exception->result->exitCode}): {$step->name}; restored the candidate snapshot. Deployment will continue.\n", important: true));
                            }
                        }
                        $this->assertNotCancelled($output);
                        $boundary = DeploymentFailureBoundary::Activation;
                        $output->emitPhase(DeploymentProgressPhase::Activation);
                        $selected = $this->deployment->activate($instance, $release);
                        // checkout_path remains the repository home, never a disposable release.
                        $this->projectRoute($instance);
                        $this->deployment->prune($instance, $selected);
                        $result = DeploymentResult::succeeded($release, $commands);
                    } catch (Throwable $exception) {
                        // An activation error may have occurred after rename; re-read the actual selection.
                        if ($boundary === DeploymentFailureBoundary::Activation) {
                            try {
                                $selected = $this->deployment->selected($instance);
                            } catch (Throwable) {
                                // Preserve the last observed selection if SSH itself is unavailable.
                            }
                        }
                        $result = DeploymentResult::failed($release, $selected, $boundary, $this->errorCode($exception), $commands);
                        if ($selected !== null && $selected->name !== $release?->name) {
                            try {
                                $this->deployment->prune($instance, $selected);
                            } catch (Throwable) {
                                $output->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, "Release cleanup failed; a later deployment will retry cleanup.\n"));
                            }
                        }
                        if ($record === null && $triggeredBy !== null) {
                            $record = $this->recorder->start($instance, $triggeredBy);
                        }
                    }
                    if ($record !== null) {
                        $this->recorder->finish($record, $result, $collector->events());
                    }

                    return $result;
                });
            });
        } catch (Throwable $exception) {
            return DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $this->errorCode($exception));
        }
    }

    public function assertAvailable(Instance $instance): void
    {
        if (! $instance->placedOnAppDev() || $instance->name !== 'default'
            || $instance->status !== InstanceState::Active
            || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('deployment_config.unavailable', 'Development deployment is available only for an active default checkout on app-dev.', 409);
        }
    }

    private function projectRoute(Instance $instance): void
    {
        $route = $instance->authoritativeRoute();
        if ($route !== null) {
            $this->projections->run(fn () => $this->routes->converge($instance, $route));
        }
    }

    private function assertNotCancelled(DeploymentRequest $request): void
    {
        if ($request->cancellation->requested()) {
            throw new ProcessCancelledException;
        }
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded') {
            return 'deployment.deadline_exceeded';
        }
        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }
        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }
        if ($exception instanceof RuntimeConvergenceException || $exception instanceof ResourceOperationException) {
            return $exception->errorCode;
        }

        return 'deployment.interrupted';
    }
}
