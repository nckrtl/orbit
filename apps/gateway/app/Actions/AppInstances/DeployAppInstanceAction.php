<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\Deployment\DeploymentCommandResult;
use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Domain\AppInstances\Deployment\DeploymentDeadline;
use App\Domain\AppInstances\Deployment\DeploymentFailureBoundary;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentSynchronizer;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Models\AppInstance;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class DeployAppInstanceAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $configs,
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentSynchronizer $environment,
        private ProductionDeployment $deployment,
        private ProductionPhpRuntimeManager $runtime,
        private CommandDeadline $deadline,
    ) {}

    public function execute(AppInstance $appInstance, ?DeploymentRequest $request = null): DeploymentResult
    {
        $request ??= DeploymentRequest::withoutOutput();

        try {
            $config = $this->configs->resolve($appInstance->refresh());
        } catch (Throwable $exception) {
            return DeploymentResult::failed(
                null,
                null,
                DeploymentFailureBoundary::Operation,
                $this->errorCode($exception),
            );
        }

        try {
            return $this->operations->run(
                [$appInstance->id],
                function () use ($appInstance, $config, $request): DeploymentResult {
                    $this->deadline->start(DeploymentDeadline::for($config)->seconds);

                    try {
                        return $this->deploy($appInstance->refresh(), $config, $request);
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

    private function deploy(
        AppInstance $appInstance,
        DeploymentConfig $config,
        DeploymentRequest $request,
    ): DeploymentResult {
        $boundary = DeploymentFailureBoundary::Preparation;
        $release = null;
        $selected = null;
        $commands = [];

        try {
            $this->assertNotCancelled($request);
            $selected = $this->deployment->selected($appInstance);
            $release = $this->deployment->prepare($appInstance, $config->branch);
            $boundary = DeploymentFailureBoundary::Environment;
            $this->assertNotCancelled($request);
            $this->environment->execute($appInstance);
            $boundary = DeploymentFailureBoundary::BeforeActivation;
            $this->executeSteps($appInstance, $release, $config, DeploymentPhase::BeforeActivation, $request, $commands);
            $boundary = DeploymentFailureBoundary::Activation;
            $this->assertNotCancelled($request);
            $selected = $this->deployment->activate($appInstance, $release);
            $appInstance->update(['checkout_path' => $selected->path]);

            if (is_string($appInstance->selected_php_version)) {
                $boundary = DeploymentFailureBoundary::CacheRefresh;
                $this->assertNotCancelled($request);
                $this->runtime->refreshCache($appInstance);
            }

            $boundary = DeploymentFailureBoundary::AfterActivation;
            $this->executeSteps($appInstance, $release, $config, DeploymentPhase::AfterActivation, $request, $commands);

            return DeploymentResult::succeeded($release, $commands);
        } catch (Throwable $exception) {
            $this->appendFailedCommand($commands, $exception);

            return DeploymentResult::failed(
                $release,
                $selected,
                $boundary,
                $this->errorCode($exception),
                $commands,
            );
        }
    }

    /**
     * @param  list<DeploymentCommandResult>  $commands
     */
    private function executeSteps(
        AppInstance $appInstance,
        DeploymentRelease $release,
        DeploymentConfig $config,
        DeploymentPhase $phase,
        DeploymentRequest $request,
        array &$commands,
    ): void {
        foreach ($config->steps as $step) {
            if ($step->phase !== $phase) {
                continue;
            }

            $this->assertNotCancelled($request);
            $commands[] = new DeploymentCommandResult(
                $step->name,
                $this->deployment->executeStep($appInstance, $release, $step, $request),
            );
        }
    }

    /** @param list<DeploymentCommandResult> $commands */
    private function appendFailedCommand(array &$commands, Throwable $exception): void
    {
        if (! $exception instanceof RuntimeConvergenceException || $exception->result === null) {
            return;
        }

        $name = str_starts_with($exception->step, 'deployment-step-')
            ? substr($exception->step, strlen('deployment-step-'))
            : $exception->step;
        $commands[] = new DeploymentCommandResult($name, $exception->result);
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
