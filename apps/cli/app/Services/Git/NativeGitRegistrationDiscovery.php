<?php

declare(strict_types=1);

namespace App\Services\Git;

use Symfony\Component\Process\Process;

final readonly class NativeGitRegistrationDiscovery implements GitRegistrationDiscovery
{
    public function inspect(string $path): ?GitRegistrationFacts
    {
        $top = $this->git($path, ['rev-parse', '--show-toplevel']);

        if ($top === null || ! str_starts_with($top, '/') || realpath($top) !== $top) {
            return null;
        }

        // The stored origin, not the URL after the user's insteadOf rewrites, is what the Gateway records.
        $repository = $this->git($top, ['config', '--get', 'remote.origin.url']);
        $commit = $this->git($top, ['rev-parse', '--verify', 'HEAD^{commit}']);

        if ($repository === null || ! GitRepositoryOriginPolicy::isSafe($repository) || $commit === null) {
            return null;
        }

        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            return null;
        }

        return new GitRegistrationFacts(path: $top, repositoryUrl: $repository);
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
