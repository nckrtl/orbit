<?php

declare(strict_types=1);

use App\Infrastructure\Fleet\GitReleaseHistory;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** @param  list<string>  $arguments */
function release_history_git(string $directory, array $arguments): string
{
    $command = ['git', '-C', $directory, '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', '-c', 'commit.gpgsign=false', ...$arguments];
    $output = [];
    exec(implode(' ', array_map(escapeshellarg(...), $command)).' 2>/dev/null', $output, $status);

    expect($status)->toBe(0);

    return trim(implode("\n", $output));
}

describe(GitReleaseHistory::class, function (): void {
    beforeEach(function (): void {
        $this->repository = sys_get_temp_dir().'/orbit-release-history-'.Str::uuid();
        mkdir($this->repository);
        release_history_git($this->repository, ['init', '--quiet', '--initial-branch=main']);

        foreach (['first', 'second', 'third'] as $message) {
            release_history_git($this->repository, ['commit', '--quiet', '--allow-empty', '-m', $message]);
        }

        $this->head = release_history_git($this->repository, ['rev-parse', 'HEAD']);
        $this->history = new GitReleaseHistory(app(ProcessRunner::class), $this->repository.'/');
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->repository);
        new Filesystem()->deleteDirectory($this->repository.'-shallow');
    });

    it('counts every commit the commit reaches, as the CLI release workflow does', function (): void {
        expect($this->history->commit($this->head))->toBe($this->head)
            ->and($this->history->commit(substr((string) $this->head, 0, 10)))->toBe($this->head)
            ->and($this->history->count($this->head))->toBe(3)
            ->and($this->history->count(release_history_git($this->repository, ['rev-parse', 'HEAD~1'])))->toBe(2);
    });

    it('lists the commits a commit reaches, newest first and without the commit itself', function (): void {
        $parent = release_history_git($this->repository, ['rev-parse', 'HEAD~1']);
        $root = release_history_git($this->repository, ['rev-parse', 'HEAD~2']);

        expect($this->history->ancestors($this->head, 20))->toBe([$parent, $root])
            ->and($this->history->ancestors($this->head, 1))->toBe([$parent])
            ->and($this->history->ancestors($root, 20))->toBe([])
            ->and($this->history->ancestors(str_repeat('0', 40), 20))->toBe([])
            ->and($this->history->ancestors('HEAD', 20))->toBe([]);
    });

    it('resolves no commit for an unknown or non-hexadecimal revision', function (string $revision): void {
        expect($this->history->commit($revision))->toBeNull();
    })->with(['unknown commit' => str_repeat('0', 40), 'branch name' => 'main', 'option' => '--all', 'tag syntax' => 'HEAD~1']);

    it('refuses to count a shallow clone', function (): void {
        release_history_git(sys_get_temp_dir(), ['clone', '--quiet', '--depth=1', 'file://'.$this->repository, $this->repository.'-shallow']);
        $shallow = new GitReleaseHistory(app(ProcessRunner::class), $this->repository.'-shallow');

        expect($shallow->commit($this->head))->toBe($this->head)
            ->and($shallow->count($this->head))->toBeNull();
    });

    it('reports nothing outside a Git repository', function (): void {
        $outside = new GitReleaseHistory(app(ProcessRunner::class), sys_get_temp_dir());

        expect($outside->commit($this->head))->toBeNull()
            ->and($outside->count($this->head))->toBeNull();
    });
});
