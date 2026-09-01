<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use SensitiveParameter;

final readonly class NativeRepositoryDefaultBranchResolver implements RepositoryDefaultBranchResolver
{
    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function resolve(#[SensitiveParameter] string $repository): string
    {
        $origin = GitRepositoryOrigin::validate($repository);
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['git', 'ls-remote', '--symref', '--exit-code', '--', $origin, 'HEAD'],
            timeout: 30.0,
        ));

        if (! $result->succeeded() || $result->truncated) {
            throw $this->failure();
        }

        $firstLine = explode("\n", $result->stdout, 2)[0] ?? '';

        if (preg_match('/\Aref: refs\/heads\/(.+)\tHEAD\z/D', $firstLine, $matches) !== 1) {
            throw $this->failure();
        }

        $branch = $matches[1] ?? null;

        if (! is_string($branch) || ! GitBranchName::isValid($branch)) {
            throw $this->failure();
        }

        return $branch;
    }

    private function failure(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'app.default_branch_unavailable',
            message: 'The repository default branch could not be resolved.',
        );
    }
}
