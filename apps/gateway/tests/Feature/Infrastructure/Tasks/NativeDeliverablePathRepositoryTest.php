<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;

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
    $project = new Project(['repository_url' => 'git@example.test:fixture.git', 'default_branch' => 'main']);
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem);
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
    $repository = new NativeDeliverablePathRepository($processes, app(RepositoryReadAccess::class), new Filesystem);
    try {
        $repository->files(new Project(['repository_url' => 'git@example.test:fixture.git']), str_repeat('b', 40));
        $this->fail('Expected an unreadable tree to fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('tasks.deliverable_base_unavailable');
        expect($exception->getMessage())->not->toContain('secret-diagnostic', 'malformed');
    }
    expect(is_dir(array_last($processes->invocations[0]->arguments)))->toBeFalse();
})->with([[1, false, ''], [0, true, ''], [0, false, 'malformed']]);
