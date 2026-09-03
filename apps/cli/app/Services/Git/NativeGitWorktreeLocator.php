<?php

declare(strict_types=1);

namespace App\Services\Git;

use Symfony\Component\Process\Process;
use Throwable;

final readonly class NativeGitWorktreeLocator implements GitWorktreeLocator
{
    public function locate(): GitWorktreeLocation
    {
        $workingDirectory = getcwd();

        if (! is_string($workingDirectory) || $workingDirectory === '') {
            throw new GitWorktreeLocatorException;
        }

        try {
            $process = new Process(
                ['git', 'rev-parse', '--show-toplevel'],
                cwd: $workingDirectory,
                timeout: 5.0,
            );
            $process->run();
        } catch (Throwable) {
            throw new GitWorktreeLocatorException;
        }

        if (! $process->isSuccessful()) {
            throw new GitWorktreeLocatorException;
        }

        $reportedTopLevel = rtrim($process->getOutput(), "\r\n");
        $topLevel = realpath($reportedTopLevel);

        if (
            ! is_string($topLevel)
            || $topLevel === ''
            || ! str_starts_with($topLevel, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $topLevel) === 1
        ) {
            throw new GitWorktreeLocatorException;
        }

        $defaultName = basename(dirname($topLevel));

        if ($defaultName === '' || $defaultName === '.' || $defaultName === '/') {
            throw new GitWorktreeLocatorException;
        }

        return new GitWorktreeLocation($topLevel, $defaultName);
    }
}
