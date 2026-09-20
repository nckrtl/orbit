<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use SensitiveParameter;
use Throwable;

final readonly class NativeRepositoryDefaultBranchResolver implements RepositoryDefaultBranchResolver
{
    public function __construct(
        private ProcessRunner $processes,
        private RepositoryReadAccess $access,
    ) {}

    public function resolve(#[SensitiveParameter] string $repository): string
    {
        $result = $this->run(new ProcessInvocation(
            arguments: ['git', '-C', '/', 'ls-remote', '--symref', '--exit-code', '--', $repository, 'HEAD'],
            timeout: 30.0,
            environment: $this->access->for($repository)->variables,
        ));

        if (! $result->succeeded() || $result->truncated) {
            throw $this->failure($repository);
        }

        $firstLine = explode("\n", $result->stdout, 2)[0];

        if (preg_match('/\Aref: refs\/heads\/(.+)\tHEAD\z/D', $firstLine, $matches) !== 1) {
            throw $this->failure($repository);
        }

        $branch = $matches[1];

        if (! GitBranchName::isValid($branch)) {
            throw $this->failure($repository);
        }

        return $branch;
    }

    public function verify(#[SensitiveParameter] string $repository, string $branch): void
    {
        $branch = GitBranchName::validate($branch);
        $reference = "refs/heads/{$branch}";
        $result = $this->run(new ProcessInvocation(
            arguments: ['git', '-C', '/', 'ls-remote', '--exit-code', '--heads', '--', $repository, $reference],
            timeout: 30.0,
            environment: $this->access->for($repository)->variables,
        ));

        if (! $result->succeeded() || $result->truncated) {
            throw $this->failure($repository);
        }

        $lines = array_values(array_filter(
            explode("\n", trim($result->stdout)),
            static fn (string $line): bool => $line !== '',
        ));

        if (count($lines) !== 1) {
            throw $this->failure($repository);
        }

        $fields = explode("\t", $lines[0], 2);

        if (
            count($fields) !== 2
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/Di', $fields[0]) !== 1
            || $fields[1] !== $reference
        ) {
            throw $this->failure($repository);
        }
    }

    private function run(ProcessInvocation $invocation): CommandResult
    {
        try {
            return $this->processes->run($invocation);
        } catch (Throwable) {
            throw $this->failure();
        }
    }

    private function failure(#[SensitiveParameter] ?string $repository = null): ResourceOperationException
    {
        $message = 'The requested repository branch could not be determined or verified.';

        if ($repository !== null && GitHubRepository::fromOrigin($repository) instanceof GitHubRepository) {
            $message .= ' A private github.com repository needs the Gateway\'s GitHub App installed on the account that owns it.';
        }

        return new ResourceOperationException(
            errorCode: 'app.default_branch_unavailable',
            message: $message,
        );
    }
}
