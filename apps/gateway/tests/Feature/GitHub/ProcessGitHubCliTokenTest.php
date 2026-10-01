<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\ProcessGitHubCliToken;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

function ghCliRunner(CommandResult|Throwable $outcome): ProcessRunner
{
    return new class($outcome) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(
            private readonly CommandResult|Throwable $outcome,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            if ($this->outcome instanceof Throwable) {
                throw $this->outcome;
            }

            return $this->outcome;
        }
    };
}

describe('ProcessGitHubCliToken', function (): void {
    it('reads the github.com token of the Gateway user without prompts', function (): void {
        $runner = ghCliRunner(new CommandResult(0, "gho_sentinel000000000000000000\n", '', 1, false));

        expect(new ProcessGitHubCliToken($runner)->token())->toBe('gho_sentinel000000000000000000')
            ->and($runner->invocations[0]->arguments)->toBe(['gh', 'auth', 'token', '--hostname', 'github.com'])
            ->and($runner->invocations[0]->environment)->toMatchArray(['GH_PROMPT_DISABLED' => '1']);
    });

    it('reports a missing login without the GitHub CLI output', function (CommandResult|Throwable $outcome): void {
        try {
            new ProcessGitHubCliToken(ghCliRunner($outcome))->token();
            test()->fail('Expected the token read to fail.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('github.cli_unauthenticated')
                ->and($exception->getMessage())->toContain('gh auth login', 'orbit user')
                ->not->toContain('diagnostic-sentinel');
        }
    })->with([
        'not logged in' => [new CommandResult(1, '', 'diagnostic-sentinel: no oauth token', 1, false)],
        'unexpected output' => [new CommandResult(0, 'diagnostic-sentinel token', '', 1, false)],
        'truncated output' => [new CommandResult(0, 'gho_sentinel000000000000000000', '', 1, true)],
        'gh not installed' => [new RuntimeException('diagnostic-sentinel: gh: command not found')],
    ]);
});
