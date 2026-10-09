<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Route;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final readonly class RemoteDevelopmentDeployment implements DevelopmentDeployment
{
    /** @var Closure(): string */
    private Closure $releaseName;

    /** @param (Closure(): string)|null $releaseName */
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
        ?Closure $releaseName = null,
    ) {
        $this->releaseName = $releaseName ?? static fn (): string => gmdate('YmdHis').'-'.bin2hex(random_bytes(8));
    }

    public function initialize(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->run($instance, DevelopmentReleaseProgram::initialize(), 'initialize');
    }

    public function target(Instance $instance): string
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing('project');
        $branch = $instance->project->default_branch;
        if (! is_string($branch) || ! GitBranchName::isValid($branch)) {
            throw $this->invalidReceipt();
        }
        $repository = GitRepositoryOrigin::validate($instance->project->repository_url);
        $script = GitReadScript::for($this->access->for($repository, $instance->project->source_access), DevelopmentReleaseProgram::target());
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance), $branch],
                input: $script->input,
                protectedInput: $script->protectedInput,
                maxOutputBytes: 4096,
            ),
            'development-deployment-target',
            'deployment.prepare_failed',
        );
        $commit = trim($result->stdout);
        $this->assertCommit($commit);

        return $commit;
    }

    public function selected(Instance $instance): DeploymentRelease
    {
        InstanceSandboxGuard::assertHostOperation($instance);

        return $this->receipt($instance, $this->run($instance, DevelopmentReleaseProgram::selected(), 'selected'));
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $result = $this->run($instance, DevelopmentReleaseProgram::releases(), 'releases');
        $lines = explode("\n", trim($result->stdout));
        $selected = null;
        $names = [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) !== 2 || ! DeploymentRelease::isValidName($parts[1])) {
                throw $this->invalidReceipt();
            }
            if ($parts[0] === 'SELECTED' && $selected === null) {
                $selected = $parts[1];
            } elseif ($parts[0] === 'RELEASE') {
                $names[] = $parts[1];
            } else {
                throw $this->invalidReceipt();
            }
        }
        if ($selected === null || ! in_array($selected, $names, true) || count(array_unique($names)) !== count($names)) {
            throw $this->invalidReceipt();
        }
        rsort($names, SORT_STRING);

        return new DeploymentReleaseState($names, $selected);
    }

    public function prepare(Instance $instance, string $commit): DeploymentRelease
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $name = ($this->releaseName)();
        if (! DeploymentRelease::isValidName($name)) {
            throw $this->invalidReceipt();
        }
        $this->assertCommit($commit);
        $release = $this->receipt($instance, $this->run($instance, DevelopmentReleaseProgram::prepare(), 'prepare', [$name, $commit, ...$this->environmentDirectories($instance)]));
        if ($release->name !== $name || $release->commit !== $commit) {
            throw $this->invalidReceipt();
        }

        return $release;
    }

    public function executeStep(Instance $instance, DeploymentRelease $release, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->assertRelease($instance, $release);
        try {
            return $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [...$this->arguments($instance), $release->name, $step->required ? '0' : '1', (string) $step->timeoutSeconds, $step->name],
                    protectedInput: ProtectedInput::fromString(str_replace('__COMMAND__', base64_encode($step->command), DevelopmentReleaseProgram::step())),
                    output: static function (ProcessOutput $output) use ($request, $step): void {
                        $request->emit(new DeploymentEvent(
                            $step->name,
                            $output->stream === ProcessOutputStream::Stdout ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr,
                            $output->value,
                        ));
                    },
                    cancelled: $request->cancellation->requested(...),
                    // Leave time for the remote timer to terminate children and restore a snapshot.
                    timeout: $step->timeoutSeconds + 30,
                ),
                'deployment-step-'.$step->name,
                'deployment.step_failed',
                commandTimeout: $step->timeoutSeconds + 30,
            );
        } catch (RuntimeConvergenceException $exception) {
            if (! $step->required) {
                try {
                    if ($exception->result === null) {
                        throw $exception;
                    }
                    // A separate receipt is not lost when command output is truncated.
                    $this->run($instance, DevelopmentReleaseProgram::restored(), 'step-restore', [$release->name, $step->name, (string) $exception->result->exitCode]);
                } catch (RuntimeConvergenceException $restore) {
                    throw new RuntimeConvergenceException($exception->step, 'deployment.step_restore_failed', 'The best-effort snapshot could not be confirmed restored.', previous: $restore, result: $exception->result);
                }
            }
            throw $exception;
        }
    }

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->assertRelease($instance, $release);
        $route = $instance->authoritativeRoute();
        if ($route !== null) {
            // Validate the Web root and grant access before publishing the candidate, not after.
            $site = new DevelopmentSite(
                nodeId: $instance->node_id,
                nodeAddress: $instance->node->wireguard_ip ?? '',
                scope: 'app-instance-'.$instance->id,
                checkoutPath: $release->path,
                documentRoot: $instance->root ?? $instance->project->root ?? '',
                phpVersion: $instance->selected_php_version,
                domain: $route->domain,
            );
            $this->ssh->execute($instance->node, new DevelopmentCaddyAccessCommand()->command(collect([$site])), 'development-deployment-web-access', 'app-dev.source_access_failed');
        }
        $selected = $this->receipt($instance, $this->run($instance, DevelopmentReleaseProgram::activate(), 'activate', [$release->name, $release->commit]));
        if ($selected != $release) {
            throw $this->invalidReceipt();
        }

        return $selected;
    }

    public function prune(Instance $instance, DeploymentRelease $selected): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->assertRelease($instance, $selected);
        $retained = [];
        foreach (Instance::query()->where('node_id', $instance->node_id)->where('project_id', $instance->project_id)
            ->where('id', '!=', $instance->id)->where('seed_repository', $instance->checkout_path)->whereNotNull('seed_path')->pluck('seed_path') as $path) {
            if (! is_string($path) || ! str_starts_with($path, $instance->checkout_path.'/releases/')) {
                throw $this->invalidReceipt();
            }
            $name = substr($path, strlen($instance->checkout_path.'/releases/'));
            if (! DeploymentRelease::isValidName($name)) {
                throw $this->invalidReceipt();
            }
            $retained[] = $name;
        }
        $this->run($instance, DevelopmentReleaseProgram::prune(), 'prune', [(string) DeploymentRelease::RETAINED_PER_HOME, $selected->name, ...array_unique($retained)]);
    }

    /**
     * The application directories below the checkout root whose environment files each candidate copies
     * from the stable home: the default directory and every directory that a Route with a web root serves.
     *
     * @return list<string>
     */
    private function environmentDirectories(Instance $instance): array
    {
        $instance->loadMissing('project');
        $webRoots = Route::query()
            ->whereNotNull('web_root')
            ->whereHas('targets', static fn (Builder $query): Builder => $query->where('instance_id', $instance->id))
            ->orderBy('id')
            ->pluck('web_root')
            ->all();
        $directories = [];
        foreach ([$instance->root ?? $instance->project->root, ...$webRoots] as $webRoot) {
            $directory = RouteWebRoot::relativeDirectory(is_string($webRoot) ? $webRoot : null);
            if ($directory !== '') {
                $directories[$directory] = RelativeWebRoot::validate($directory);
            }
        }

        return array_values($directories);
    }

    /** @return non-empty-list<string> */
    private function arguments(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);
        $this->removal->instanceRoot($instance, $this->accounts->resolve($instance->node));
        if (! $instance->placedOnAppDev() || $instance->name !== 'default') {
            throw $this->invalidReceipt();
        }

        return ['bash', '-seu', '--', $instance->checkout_path, GitRepositoryOrigin::validate($instance->project->repository_url), (string) $instance->id];
    }

    /** @param list<string> $extra */
    private function run(Instance $instance, string $program, string $step, array $extra = []): CommandResult
    {
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(arguments: [...$this->arguments($instance), ...$extra], input: $program, maxOutputBytes: 4096),
            'development-deployment-'.$step,
            'deployment.'.$step.'_failed',
        );
        if ($step === 'releases' || $step === 'prune') {
            foreach (explode("\n", $result->stderr) as $line) {
                $parts = explode("\t", $line);
                if (count($parts) !== 3) {
                    continue;
                }
                $broken = DeploymentRelease::isValidName($parts[1]) && in_array($parts[2], ['missing-worktree-admin', 'missing-git'], true);
                if ($parts[0] === 'SKIPPED_BROKEN_RELEASE' && $broken) {
                    Log::warning('Skipping owned broken development release.', ['instance_id' => $instance->id, 'release' => $parts[1], 'reason' => $parts[2]]);
                } elseif ($parts[0] === 'REMOVED_BROKEN_RELEASE' && $broken) {
                    Log::info('Removed owned broken development release.', ['instance_id' => $instance->id, 'release' => $parts[1], 'reason' => $parts[2]]);
                } elseif ($parts[0] === 'RETAINED_OVER_LIMIT' && ctype_digit($parts[1]) && ctype_digit($parts[2])) {
                    Log::warning('Leased seeds keep more development releases than the limit.', ['instance_id' => $instance->id, 'retained' => (int) $parts[1], 'limit' => (int) $parts[2]]);
                }
            }
        }

        return $result;
    }

    private function receipt(Instance $instance, CommandResult $result): DeploymentRelease
    {
        $parts = explode("\t", trim($result->stdout));
        if (count($parts) !== 2 || ! DeploymentRelease::isValidName($parts[0])) {
            throw $this->invalidReceipt();
        }
        $this->assertCommit($parts[1]);

        return new DeploymentRelease($parts[0], $instance->checkout_path.'/releases/'.$parts[0], $parts[1]);
    }

    private function assertRelease(Instance $instance, DeploymentRelease $release): void
    {
        if (! DeploymentRelease::isValidName($release->name) || $release->path !== $instance->checkout_path.'/releases/'.$release->name) {
            throw $this->invalidReceipt();
        }
        $this->assertCommit($release->commit);
    }

    private function assertCommit(string $commit): void
    {
        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $commit) !== 1) {
            throw $this->invalidReceipt();
        }
    }

    private function invalidReceipt(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.invalid_release', 'Development deployment returned an invalid release.', 409);
    }
}
