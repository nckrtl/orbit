<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\DeliverablePathWorkspace;
use Tests\Support\TestOrbitHome;

it('reads a provisional default branch SHA and a complete immutable tree in a disposable repository', function (): void {
    $commit = str_repeat('a', 40);
    $processes = new class($commit) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(private string $commit) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;
            $output = match (count($this->invocations)) {
                1 => $this->commit."\trefs/heads/main\n",
                4 => '100644 blob '.$this->commit."\ttests/Space NameTest.php\0".'160000 commit '.$this->commit."\tmodule\0",
                default => '',
            };

            return new CommandResult(0, $output, '', 0, false);
        }
    };
    $project = new Project(['repository_url' => 'git@example.test:fixture.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::unreachableExecutor());
    expect($repository->defaultBranchCommit($project))->toBe($commit);
    expect($repository->files($project, $commit))->toBe(['tests/Space NameTest.php']);
    $directory = array_last($processes->invocations[1]->arguments);
    $git = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false'];
    expect(is_dir($directory))->toBeFalse();
    expect($processes->invocations[0]->arguments)->toBe([...$git, '-C', '/', 'ls-remote', '--exit-code', '--heads', '--', $project->repository_url, 'refs/heads/main']);
    expect($processes->invocations[2]->arguments)->toBe([...$git, '-C', $directory, 'fetch', '--no-tags', '--depth=1', '--filter=blob:none', '--', $project->repository_url, $commit]);
    expect($processes->invocations[3]->arguments)->toBe([...$git, '-C', $directory, 'ls-tree', '-r', '-z', $commit]);
    expect($processes->invocations[3]->maxOutputBytes)->toBe(16_777_216);
});

it('fails closed on incomplete or failed base reads and removes the disposable repository', function (int $exit, bool $truncated, string $output): void {
    $processes = new class($exit, $truncated, $output) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(private int $exit, private bool $truncated, private string $output) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return count($this->invocations) < 3
                ? new CommandResult(0, '', '', 0, false)
                : new CommandResult($this->exit, $this->output, 'secret-diagnostic', 0, $this->truncated);
        }
    };
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::unreachableExecutor());
    try {
        $repository->files(new Project(['repository_url' => 'git@example.test:fixture.git', 'apps' => fixture_apps(null)]), str_repeat('b', 40));
        $this->fail('Expected an unreadable tree to fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('tasks.deliverable_base_unavailable');
        expect($exception->getMessage())->not->toContain('secret-diagnostic', 'malformed');
    }
    expect(is_dir(array_last($processes->invocations[0]->arguments)))->toBeFalse();
})->with([[1, false, ''], [0, true, ''], [0, false, 'malformed']]);

/** @return array{0: Project, 1: Instance, 2: array{origin: string, checkout: string, pushed: string, local: string}} */
function deliverable_workspace_fixture(): array
{
    $git = DeliverablePathWorkspace::repositories();
    $project = Project::query()->create(['name' => 'Workspace base', 'slug' => 'workspace-base', 'repository_url' => 'git@example.test:workspace-base.git', 'default_branch' => 'main', 'apps' => fixture_apps(null)]);
    // Stored origins need a host. The reader passes this one to Git unchanged, so a local bare repository stands in.
    $project->setRawAttributes([...$project->getAttributes(), 'repository_url' => $git['origin']]);
    $node = Node::query()->create(['name' => 'workspace-base-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.131', 'wireguard_ip' => '10.44.0.131']);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'workspace-base', 'checkout_path' => $git['checkout'], 'status' => 'source_resolved']);

    return [$project, $workspace, $git];
}

/** Records every origin read while running the real Git commands. */
function deliverable_recording_processes(): ProcessRunner
{
    return new class(app(ProcessRunner::class)) implements ProcessRunner
    {
        /** @var list<list<string>> */
        public array $arguments = [];

        public function __construct(private ProcessRunner $inner) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->arguments[] = $invocation->arguments;

            return $this->inner->run($invocation);
        }
    };
}

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('reads an approved commit that exists only in the task workspace without reading the origin', function (): void {
    [$project, $workspace, $git] = deliverable_workspace_fixture();
    $processes = deliverable_recording_processes();
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::localExecutor());

    expect($repository->files($project, $git['local'], $workspace))->toEqualCanonicalizing(['app/Pushed.php', 'app/LocalOnly.php']);
    expect($processes->arguments)->toBe([]);
});

it('reads the origin when the workspace cannot list the commit', function (string $source): void {
    [$project, $workspace, $git] = deliverable_workspace_fixture();
    $processes = deliverable_recording_processes();
    $executor = $source === 'unreachable' ? DeliverablePathWorkspace::unreachableExecutor() : DeliverablePathWorkspace::localExecutor();
    if ($source === 'missing') {
        // The workspace was reset past the pushed commit, so only the origin still has it.
        new Process(['rm', '-rf', $git['checkout'].'/.git'])->mustRun();
        new Process(['git', 'init', '--quiet', $git['checkout']])->mustRun();
    }
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem, $executor);

    expect($repository->files($project, $git['pushed'], $workspace))->toBe(['app/Pushed.php']);
    expect(array_filter($processes->arguments, static fn (array $arguments): bool => in_array('fetch', $arguments, true)))->toHaveCount(1);
})->with(['unreachable', 'missing']);

it('fails closed when neither the workspace nor the origin has the commit', function (): void {
    [$project, $workspace] = deliverable_workspace_fixture();
    $repository = new NativeDeliverablePathRepository(app(ProcessRunner::class), app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::localExecutor());

    try {
        $repository->files($project, str_repeat('e', 40), $workspace);
        $this->fail('Expected a commit missing from both sources to fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('tasks.deliverable_base_unavailable');
    }
});
