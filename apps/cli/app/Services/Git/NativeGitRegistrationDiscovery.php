<?php

declare(strict_types=1);

namespace App\Services\Git;

use Symfony\Component\Process\Process;

/** @mago-expect lint:cyclomatic-complexity Git discovery rejects each incomplete or unsafe repository fact independently. */
final readonly class NativeGitRegistrationDiscovery implements GitRegistrationDiscovery
{
    public function inspect(string $path): ?GitRegistrationFacts
    {
        $top = $this->git($path, ['rev-parse', '--show-toplevel']);

        if ($top === null || ! str_starts_with($top, '/') || realpath($top) !== $top) {
            return null;
        }

        $repository = $this->git($top, ['remote', 'get-url', 'origin']);
        $commit = $this->git($top, ['rev-parse', '--verify', 'HEAD^{commit}']);

        if ($repository === null || $commit === null) {
            return null;
        }

        $originHead = $this->git($top, ['symbolic-ref', '--short', 'refs/remotes/origin/HEAD']);
        $defaultBranch = $originHead === null
            ? null
            : preg_replace(pattern: '/\Aorigin\//', replacement: '', subject: $originHead);
        $branch = $this->git($top, ['symbolic-ref', '--short', 'HEAD']);
        $repositoryPath = str_starts_with($repository, 'git@')
            ? explode(separator: ':', string: $repository, limit: 2)[1] ?? ''
            : (string) parse_url($repository, PHP_URL_PATH);
        $repositoryName = preg_replace(
            pattern: '/\.git\z/',
            replacement: '',
            subject: rtrim(string: $repositoryPath, characters: '/'),
        );
        $slug = strtolower(basename($repositoryName ?? ''));
        $dotGit = "{$top}/.git";

        if ($slug === '' || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            return null;
        }

        return new GitRegistrationFacts(
            path: $top,
            repositoryUrl: $repository,
            slug: $slug,
            defaultBranch: is_string($defaultBranch) && $defaultBranch !== '' ? $defaultBranch : null,
            branch: $branch,
            root: is_file("{$top}/composer.json") && is_file("{$top}/artisan") && is_dir("{$top}/public")
                ? 'public'
                : null,
            layout: is_dir($dotGit) && ! is_link($dotGit) ? 'checkout' : 'worktree',
            commit: $commit,
        );
    }

    /** @param list<string> $arguments */
    private function git(string $path, array $arguments): ?string
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $value = trim($process->getOutput());

        return $value === '' ? null : $value;
    }
}
