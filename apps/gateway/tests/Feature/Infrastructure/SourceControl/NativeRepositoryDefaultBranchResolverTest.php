<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->repositoryDirectory = sys_get_temp_dir().'/orbit-branch-resolver-'.Str::uuid();
    $this->workingRepository = $this->repositoryDirectory.'/working';
    $this->bareRepository = $this->repositoryDirectory.'/origin.git';
    $files = new Filesystem;
    $files->makeDirectory($this->repositoryDirectory, 0700, true);
    $files->makeDirectory($this->workingRepository, 0700, true);
    $runner = new NativeProcessRunner;

    foreach ([
        ['git', '-C', $this->workingRepository, 'init', '--initial-branch=main'],
        ['git', '-C', $this->workingRepository, 'config', 'user.name', 'Orbit Tests'],
        ['git', '-C', $this->workingRepository, 'config', 'user.email', 'orbit@example.test'],
    ] as $arguments) {
        expect($runner->run(new ProcessInvocation($arguments))->succeeded())->toBeTrue();
    }

    $files->put($this->workingRepository.'/README.md', "# Fixture\n");

    foreach ([
        ['git', '-C', $this->workingRepository, 'add', 'README.md'],
        ['git', '-C', $this->workingRepository, 'commit', '-m', 'Initial fixture'],
        ['git', '-C', $this->workingRepository, 'branch', 'stable'],
        ['git', 'clone', '--bare', '--', $this->workingRepository, $this->bareRepository],
    ] as $arguments) {
        expect($runner->run(new ProcessInvocation($arguments))->succeeded())->toBeTrue();
    }
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->repositoryDirectory);
});

it('resolves a real bare repository default and observes a later remote default change', function (): void {
    $resolver = new NativeRepositoryDefaultBranchResolver(new NativeProcessRunner);

    expect($resolver->resolve($this->bareRepository))->toBe('main');

    $changed = new NativeProcessRunner()->run(new ProcessInvocation([
        'git',
        '-C',
        $this->bareRepository,
        'symbolic-ref',
        'HEAD',
        'refs/heads/stable',
    ]));
    expect($changed->succeeded())->toBeTrue();

    expect($resolver->resolve($this->bareRepository))->toBe('stable');
});

it('verifies a real explicit branch and rejects a missing one', function (): void {
    $resolver = new NativeRepositoryDefaultBranchResolver(new NativeProcessRunner);

    $resolver->verify($this->bareRepository, 'stable');

    expect(fn () => $resolver->verify($this->bareRepository, 'missing'))
        ->toThrow(ResourceOperationException::class, 'could not be determined or verified');
});

it('maps malformed symbolic HEAD to the stable branch error', function (): void {
    $changed = new NativeProcessRunner()->run(new ProcessInvocation([
        'git',
        '-C',
        $this->bareRepository,
        'symbolic-ref',
        'HEAD',
        'refs/heads/missing',
    ]));
    expect($changed->succeeded())->toBeTrue();

    expect(fn (): string => new NativeRepositoryDefaultBranchResolver(new NativeProcessRunner)
        ->resolve($this->bareRepository))
        ->toThrow(ResourceOperationException::class, 'could not be determined or verified');
});

it('maps an inaccessible repository to the stable branch error', function (): void {
    $missingRepository = $this->repositoryDirectory.'/missing.git';

    expect(
        fn (): string => new NativeRepositoryDefaultBranchResolver(new NativeProcessRunner)
            ->resolve($missingRepository),
    )
        ->toThrow(ResourceOperationException::class, 'could not be determined or verified');
});

it('uses bounded argv-only Git calls and redacts timeout, error, and malformed output details', function (
    CommandResult $result,
): void {
    $processes = new class($result) implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(
            private readonly CommandResult $result,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return $this->result;
        }
    };
    $repository = 'https://example.test/private-sentinel.git';
    $resolver = new NativeRepositoryDefaultBranchResolver($processes);

    try {
        $resolver->resolve($repository);
        test()->fail('Expected branch resolution to fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('app.default_branch_unavailable')
            ->and($exception->getMessage())
            ->toBe('The requested repository branch could not be determined or verified.')
            ->not->toContain('private-sentinel', 'diagnostic-sentinel');
    }

    expect($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->arguments)
        ->toBe(['git', 'ls-remote', '--symref', '--exit-code', '--', $repository, 'HEAD'])
        ->and($processes->invocations[0]->timeout)
        ->toBe(30.0);
})->with([
    'timeout or command error' => [new CommandResult(124, '', 'diagnostic-sentinel', 30_000, false)],
    'truncated output' => [new CommandResult(0, 'ref: refs/heads/main\tHEAD', '', 1, true)],
    'malformed output' => [new CommandResult(0, 'diagnostic-sentinel', '', 1, false)],
]);
