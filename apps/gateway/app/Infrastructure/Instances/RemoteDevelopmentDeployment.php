<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Closure;

final readonly class RemoteDevelopmentDeployment implements DevelopmentDeployment
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
    ) {}

    public function convert(Instance $instance): string
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $result = $this->run($instance, DevelopmentCheckoutProgram::convert(), 'convert', [$this->branch($instance)]);

        return $this->commit(trim($result->stdout));
    }

    public function target(Instance $instance): DevelopmentTarget
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $repository = GitRepositoryOrigin::validate($instance->project->repository_url);
        $script = GitReadScript::for($this->access->for($repository, $instance->project->source_access), DevelopmentCheckoutProgram::target());
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance), $this->branch($instance)],
                input: $script->input,
                protectedInput: $script->protectedInput,
                maxOutputBytes: 4096,
            ),
            'development-deployment-target',
            'deployment.prepare_failed',
        );
        $lines = explode("\n", trim($result->stdout));
        if (count($lines) > 2 || (count($lines) === 2 && $lines[1] !== 'LEGACY')) {
            throw $this->invalidOutput();
        }

        return new DevelopmentTarget($this->commit($lines[0]), count($lines) === 2);
    }

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->commit($commit);
        $result = $this->run($instance, DevelopmentCheckoutProgram::checkout(), 'checkout', [$this->branch($instance), $commit], self::stream('checkout', $request));
        if (trim($result->stdout) !== $commit) {
            throw $this->invalidOutput();
        }
    }

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
    {
        InstanceSandboxGuard::assertHostOperation($instance);

        return $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance), (string) $step->timeoutSeconds],
                protectedInput: ProtectedInput::fromString(str_replace('__COMMAND__', base64_encode($step->command), DevelopmentCheckoutProgram::step())),
                output: self::stream($step->name, $request),
                cancelled: $request->cancellation->requested(...),
                // Leave time for the remote timer to terminate the step's children.
                timeout: $step->timeoutSeconds + 30,
            ),
            'deployment-step-'.$step->name,
            'deployment.step_failed',
            commandTimeout: $step->timeoutSeconds + 30,
        );
    }

    public function removeReleases(Instance $instance, array $consumers): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->run($instance, DevelopmentCheckoutProgram::removeReleases(), 'release_removal', array_map(
            static fn (string $checkout): string => StoragePath::parse($checkout)->value,
            $consumers,
        ));
    }

    /** @return Closure(ProcessOutput): void */
    private static function stream(string $name, DeploymentRequest $request): Closure
    {
        return static function (ProcessOutput $output) use ($request, $name): void {
            $request->emit(new DeploymentEvent(
                $name,
                $output->stream === ProcessOutputStream::Stdout ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr,
                $output->value,
            ));
        };
    }

    private function branch(Instance $instance): string
    {
        $instance->loadMissing('project');
        $branch = $instance->project->default_branch;
        if (! is_string($branch) || ! GitBranchName::isValid($branch)) {
            throw $this->invalidOutput();
        }

        return $branch;
    }

    /** @return non-empty-list<string> */
    private function arguments(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);
        $this->removal->instanceRoot($instance, $this->accounts->resolve($instance->node));
        if (! $instance->placedOnAppDev() || $instance->name !== 'default') {
            throw $this->invalidOutput();
        }

        return ['bash', '-seu', '--', $instance->checkout_path, GitRepositoryOrigin::validate($instance->project->repository_url), (string) $instance->id];
    }

    /**
     * @param  list<string>  $extra
     * @param  (Closure(ProcessOutput): void)|null  $output
     */
    private function run(Instance $instance, string $program, string $step, array $extra = [], ?Closure $output = null): CommandResult
    {
        try {
            return $this->ssh->execute(
                $instance->node,
                new RemoteCommand(arguments: [...$this->arguments($instance), ...$extra], input: $program, output: $output, maxOutputBytes: 8192),
                'development-deployment-'.$step,
                'deployment.'.$step.'_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            throw match ($exception->result?->exitCode) {
                DevelopmentCheckoutProgram::Dirty => new ResourceOperationException('deployment.checkout_dirty', 'The checkout has uncommitted changes to tracked files.', 409, $exception),
                DevelopmentCheckoutProgram::Busy => new ResourceOperationException('instance.lifecycle_busy', 'A setup or teardown step is running in the checkout.', 409, $exception),
                default => $exception,
            };
        }
    }

    private function commit(string $commit): string
    {
        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $commit) !== 1) {
            throw $this->invalidOutput();
        }

        return $commit;
    }

    private function invalidOutput(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.invalid_output', 'Development deployment returned invalid output.', 409);
    }
}
