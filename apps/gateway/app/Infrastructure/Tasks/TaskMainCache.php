<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final readonly class TaskMainCache
{
    public function __construct(private ProcessRunner $process) {}

    /** @return array<string, string> */
    public function publications(): array
    {
        $repository = (string) config('orbit.tasks.cache_repository', base_path('../..'));
        $result = $this->process->run(new ProcessInvocation(
            ['git', '-C', $repository, 'rev-parse', '--path-format=absolute', '--git-common-dir'],
            timeout: 10.0,
            maxOutputBytes: 4096,
        ));
        if (! $result->succeeded() || $result->truncated) {
            return [];
        }
        $store = realpath(trim($result->stdout).'/orbit-tia/v1');
        if ($store === false) {
            return [];
        }

        $files = [];
        $bytes = 0;
        foreach (['apps-cli', 'apps-docs', 'apps-gateway', 'apps-e2e', 'packages-php-sdk'] as $project) {
            foreach (["published/{$project}.json", "quality/{$project}/pint.json", "quality/{$project}/phpstan.json"] as $relative) {
                $path = $store.'/'.$relative;
                $resolved = realpath($path);
                if ($resolved !== $path || ! is_file($path) || ! is_readable($path)) {
                    continue;
                }
                $size = filesize($path);
                if ($size === false || $size > 32_000_000 || $bytes + $size > 64_000_000) {
                    continue;
                }
                $limit = min(32_000_000, 64_000_000 - $bytes);
                $contents = file_get_contents($path, length: $limit + 1);
                if ($contents === false || strlen($contents) > $limit) {
                    continue;
                }
                $bytes += strlen($contents);
                $files[$relative] = $contents;
            }
        }

        return $files;
    }
}
