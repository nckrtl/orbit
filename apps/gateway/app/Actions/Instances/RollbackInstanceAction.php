<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class RollbackInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $configs,
        private InstanceEnvironmentOperationLock $operations,
        private ProductionDeployment $deployment,
        private ProductionPhpRuntimeManager $runtime,
        private CommandDeadline $deadline,
    ) {}

    public function execute(
        Instance $instance,
        string $releaseName,
        ?DeploymentRequest $request = null,
    ): DeploymentResult {
        $request ??= DeploymentRequest::withoutOutput();

        try {
            $this->configs->assertAvailable($instance->refresh());

            return $this->operations->run(
                [$instance->id],
                fn (): DeploymentResult => $this->deadline->within(900, fn (): DeploymentResult => $this->rollback($instance->refresh(), $releaseName, $request)),
            );
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                null,
                null,
                DeploymentFailureBoundary::Operation,
                $this->errorCode($exception),
            );
        }
    }

    private function rollback(
        Instance $instance,
        string $releaseName,
        DeploymentRequest $request,
    ): DeploymentResult {
        InstanceAppProjection::assertAvailable([$instance->id]);
        $boundary = DeploymentFailureBoundary::RollbackSelection;
        $release = null;
        $selected = null;

        try {
            $request->emitPhase(DeploymentProgressPhase::Rollback);
            $this->assertNotCancelled($request);
            $selected = $this->deployment->selected($instance);
            $release = $this->deployment->retained($instance, $releaseName);
            $boundary = DeploymentFailureBoundary::Activation;
            $this->assertNotCancelled($request);
            $selected = $this->deployment->activate($instance, $release);
            $instance->update(['checkout_path' => $selected->path]);

            if (is_string($instance->selected_php_version)) {
                $boundary = DeploymentFailureBoundary::CacheRefresh;
                $this->assertNotCancelled($request);
                $this->runtime->converge($instance);
                $this->runtime->refreshCache($instance);
            }

            return DeploymentResult::succeeded($release);
        } catch (Throwable $exception) {
            if ($boundary === DeploymentFailureBoundary::Activation) {
                $selected = $this->selectionAfterActivationFailure($instance, $selected);
            }

            return DeploymentResult::failed(
                $release,
                $selected,
                $boundary,
                $this->errorCode($exception),
            );
        }
    }

    private function selectionAfterActivationFailure(
        Instance $instance,
        ?DeploymentRelease $lastKnownSelection,
    ): ?DeploymentRelease {
        try {
            $selected = $this->deployment->selected($instance);
        } catch (Throwable) {
            return $lastKnownSelection;
        }

        if ($selected !== null) {
            try {
                $instance->update(['checkout_path' => $selected->path]);
            } catch (Throwable) {
                return $selected;
            }
        }

        return $selected;
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

        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException) {
            return $exception->errorCode;
        }

        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }

        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }

        return 'deployment.interrupted';
    }
}
