<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\Deployment\DeploymentFailureBoundary;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\AppInstance;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class RollbackAppInstanceAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $configs,
        private AppInstanceEnvironmentOperationLock $operations,
        private ProductionDeployment $deployment,
        private ProductionPhpRuntimeManager $runtime,
        private CommandDeadline $deadline,
    ) {}

    public function execute(
        AppInstance $appInstance,
        string $releaseName,
        ?DeploymentRequest $request = null,
    ): DeploymentResult {
        $request ??= DeploymentRequest::withoutOutput();

        try {
            $this->configs->assertAvailable($appInstance->refresh());

            return $this->operations->run(
                [$appInstance->id],
                function () use ($appInstance, $releaseName, $request): DeploymentResult {
                    $this->deadline->start(900);

                    try {
                        return $this->rollback($appInstance->refresh(), $releaseName, $request);
                    } finally {
                        $this->deadline->clear();
                    }
                },
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
        AppInstance $appInstance,
        string $releaseName,
        DeploymentRequest $request,
    ): DeploymentResult {
        $boundary = DeploymentFailureBoundary::RollbackSelection;
        $release = null;
        $selected = null;

        try {
            $this->assertNotCancelled($request);
            $selected = $this->deployment->selected($appInstance);
            $release = $this->deployment->retained($appInstance, $releaseName);
            $boundary = DeploymentFailureBoundary::Activation;
            $this->assertNotCancelled($request);
            $selected = $this->deployment->activate($appInstance, $release);
            $appInstance->update(['checkout_path' => $selected->path]);

            if (is_string($appInstance->selected_php_version)) {
                $boundary = DeploymentFailureBoundary::CacheRefresh;
                $this->assertNotCancelled($request);
                $this->runtime->refreshCache($appInstance);
            }

            return DeploymentResult::succeeded($release);
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                $release,
                $selected,
                $boundary,
                $this->errorCode($exception),
            );
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
        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException) {
            return $exception->errorCode;
        }

        if ($exception instanceof ProcessCancelledException) {
            return 'deployment.cancelled';
        }

        if ($exception instanceof ProcessTimedOutException) {
            return 'deployment.command_timed_out';
        }

        if ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'deadline was exceeded')) {
            return 'deployment.deadline_exceeded';
        }

        return 'deployment.interrupted';
    }
}
