<?php

declare(strict_types=1);

use App\Services\Git\GitWorktreeLocatorException;
use App\Services\Git\NativeGitWorktreeLocator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/orbit-cli-worktree-locator-'.Str::uuid();
    $this->originalWorkingDirectory = getcwd();
    $this->files->makeDirectory($this->sandbox, 0o755, true);
});

afterEach(function (): void {
    if (is_string($this->originalWorkingDirectory)) {
        chdir($this->originalWorkingDirectory);
    }

    $this->files->deleteDirectory($this->sandbox);
});

it('resolves the canonical top level and its parent name from a nested worktree directory', function (): void {
    $primary = $this->sandbox.'/primary';
    $worktree = $this->sandbox.'/worktrees/dfb5/acme';
    $nested = $worktree.'/src/Feature';
    orb105_cli_git(['init', '--initial-branch=main', $primary]);
    orb105_cli_git(['-C', $primary, 'config', 'user.name', 'Orbit Test']);
    orb105_cli_git(['-C', $primary, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($primary.'/README.md', "main\n");
    orb105_cli_git(['-C', $primary, 'add', 'README.md']);
    orb105_cli_git(['-C', $primary, 'commit', '-m', 'Main']);
    $this->files->makeDirectory(dirname($worktree), 0o755, true);
    orb105_cli_git(['-C', $primary, 'worktree', 'add', '--detach', $worktree, 'HEAD']);
    $this->files->makeDirectory($nested, 0o755, true);
    chdir($nested);

    $location = new NativeGitWorktreeLocator()->locate();

    expect($location->topLevel)
        ->toBe($worktree)
        ->and($location->defaultName)
        ->toBe('dfb5');
});

it('returns a bounded error outside a Git checkout', function (): void {
    chdir($this->sandbox);

    expect(fn (): \App\Services\Git\GitWorktreeLocation => new NativeGitWorktreeLocator()->locate())
        ->toThrow(GitWorktreeLocatorException::class, 'Run this command inside a Git worktree.');
});

/** @param non-empty-list<string> $arguments */
function orb105_cli_git(array $arguments): void
{
    $process = new Process(['git', ...$arguments]);
    $process->mustRun();
}
