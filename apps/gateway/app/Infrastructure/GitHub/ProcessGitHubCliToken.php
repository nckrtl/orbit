<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubCliToken;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Throwable;

/**
 * Runs `gh auth token` as the Gateway's own user. The token stays in memory; neither the result
 * nor an error carries it.
 */
final readonly class ProcessGitHubCliToken implements GitHubCliToken
{
    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function token(): string
    {
        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: ['gh', 'auth', 'token', '--hostname', 'github.com'],
                timeout: 30.0,
                maxOutputBytes: 4096,
                environment: $this->environment(),
            ));
        } catch (Throwable) {
            throw self::unauthenticated();
        }

        return $this->tokenFrom($result);
    }

    private function tokenFrom(CommandResult $result): string
    {
        $token = trim($result->stdout);

        if (! $result->succeeded() || $result->truncated || preg_match('/\A[A-Za-z0-9_]{20,255}\z/D', $token) !== 1) {
            throw self::unauthenticated();
        }

        return $token;
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = ['GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'];
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
            $home = is_array($account) ? (string) $account['dir'] : '';
        }

        if ($home !== '') {
            $environment['HOME'] = $home;
        }

        return $environment;
    }

    private static function unauthenticated(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'github.cli_unauthenticated',
            message: 'The Gateway\'s GitHub CLI has no github.com login. Install gh on the Gateway host and run `gh auth login` as the orbit user.',
        );
    }
}
