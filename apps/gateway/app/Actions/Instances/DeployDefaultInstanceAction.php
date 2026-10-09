<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
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
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\Instance;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Deploys a development default in its own checkout: fetch, check out the branch's newest commit,
 * and run the Project's development deploy steps there. The default's seed fields record the last
 * commit whose steps all passed; other Instances on the Node start from that commit.
 */
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
        private AppDevSourceOperationLock $sources,
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
                    $commit = null;
                    $commands = [];
                    $boundary = DeploymentFailureBoundary::Preparation;
                    try {
                        $this->assertNotCancelled($output);
                        $output->emitPhase(DeploymentProgressPhase::SourcePreparation);
                        if ($instance->development_release_layout) {
                            $this->convert($instance);
                        }
                        // The pending mark is the receipt of route convergence. Repair before the unchanged
                        // fast path so a lost process cannot strand it; a completed projection skips the lock.
                        if ($instance->development_projection_pending) {
                            $this->projectRoute($instance);
                        }
                        $target = $this->deployment->target($instance);
                        if ($target->releasesRemain) {
                            $this->removeReleases($instance, $output);
                        }
                        if ($onlyChanged && $instance->seed_commit === $target->commit) {
                            return null;
                        }
                        if ($triggeredBy !== null) {
                            $record = $this->recorder->start($instance, $triggeredBy);
                        }
                        $commit = $target->commit;
                        $this->deployment->checkout($instance, $commit, $output);
                        $boundary = DeploymentFailureBoundary::BeforeActivation;
                        foreach ($steps as $step) {
                            $this->assertNotCancelled($output);
                            $output->emitPhase(DeploymentProgressPhase::BeforeActivation, $step->name);
                            try {
                                $commands[] = new DeploymentCommandResult($step->name, $this->deployment->executeStep($instance, $step, $output));
                            } catch (RuntimeConvergenceException $exception) {
                                if ($exception->result !== null) {
                                    $commands[] = new DeploymentCommandResult($step->name, $exception->result);
                                }
                                if ($step->required || $exception->result === null || $exception->errorCode !== 'deployment.step_failed') {
                                    throw $exception;
                                }
                                $output->emit(new DeploymentEvent($step->name, DeploymentOutputStream::Stderr, "Best-effort step failed (exit {$exception->result->exitCode}): {$step->name}. Deployment will continue.\n", important: true));
                            }
                        }
                        $instance->update(['seed_path' => $instance->checkout_path, 'seed_commit' => $commit, 'seed_repository' => $instance->checkout_path]);
                        $result = DeploymentResult::checkedOut($commit, $commands);
                    } catch (Throwable $exception) {
                        // The checkout stays at the new commit. The seed keeps the last commit that deployed,
                        // so the next tick retries the steps.
                        $result = DeploymentResult::failed(null, null, $boundary, $this->errorCode($exception), $commands, $commit);
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

    /**
     * Converts the old release layout once. Its Route then serves the checkout, and every Instance
     * that a release seeded names the checkout as its seed instead.
     */
    private function convert(Instance $instance): void
    {
        $commit = $this->deployment->convert($instance);
        $this->sources->synchronized($instance->node_id, function () use ($instance, $commit): void {
            $instance->update([
                'development_release_layout' => false,
                'development_projection_pending' => true,
                'seed_path' => $instance->checkout_path,
                'seed_commit' => $commit,
                'seed_repository' => $instance->checkout_path,
            ]);
            $this->releaseSeeds($instance);
        });
    }

    /**
     * Removes the old releases once no setup step, teardown step, or task baseline runs in an Instance
     * that this default seeded. A refusal leaves them for a later deployment and does not fail this one.
     */
    private function removeReleases(Instance $instance, DeploymentRequest $output): void
    {
        try {
            $seeded = $this->sources->synchronized($instance->node_id, fn (): Collection => $this->releaseSeeds($instance));
            // A baseline's setup steps read the seed path from when the check started, possibly a release.
            if ($this->baselineRuns($seeded)) {
                $output->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, "The old releases remain while a task baseline runs; a later deployment removes them.\n"));

                return;
            }
            $kept = $this->deployment->removeReleases($instance, $seeded->pluck('checkout_path')->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')->values()->all());
        } catch (Throwable $exception) {
            Log::warning('The old development releases remain; a later deployment retries their removal.', [
                'instance_id' => $instance->id,
                'error' => $exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException ? $exception->errorCode : $exception::class,
            ]);
            $output->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, "The old releases remain; a later deployment retries their removal.\n"));

            return;
        }
        if ($kept !== []) {
            Log::warning('Orbit kept release folders it does not own; remove them by hand.', ['instance_id' => $instance->id, 'releases' => $kept]);
            $output->emit(new DeploymentEvent('cleanup', DeploymentOutputStream::Stderr, 'Orbit kept release folders it does not own; remove them by hand: '.implode(', ', $kept)."\n", important: true));
        }
    }

    /**
     * Points every seed in this default's releases at its checkout, and returns the Instances seeded
     * from this default. Call it with the Node's source lock held.
     *
     * @return Collection<int, Instance>
     */
    private function releaseSeeds(Instance $instance): Collection
    {
        $releases = $instance->checkout_path.'/releases/';
        $seeded = Instance::query()->where('node_id', $instance->node_id)->where('seed_repository', $instance->checkout_path)->whereKeyNot($instance->id)->get();
        foreach ($seeded as $consumer) {
            if (is_string($consumer->seed_path) && str_starts_with($consumer->seed_path, $releases)) {
                $consumer->update(['seed_path' => $instance->checkout_path]);
            }
        }

        return $seeded;
    }

    /** @param Collection<int, Instance> $seeded */
    private function baselineRuns(Collection $seeded): bool
    {
        return TaskCheck::query()
            ->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Running->value)
            ->whereHas('task.parent', static fn (Builder $group): Builder => $group
                ->where('taskable_type', new Instance()->getMorphClass())
                ->whereIn('taskable_id', $seeded->modelKeys()))
            ->exists();
    }

    private function projectRoute(Instance $instance): void
    {
        $route = $instance->authoritativeRoute();
        if ($route !== null) {
            $instance->update(['development_projection_pending' => true]);
            $this->projections->run(fn () => $this->routes->converge($instance, $route));
            $instance->update(['development_projection_pending' => false]);
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
