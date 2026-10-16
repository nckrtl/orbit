<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Models\Node;
use Closure;
use LogicException;
use SensitiveParameter;

/** Answers database-servers.write-valkey-acl and records each input with the Node it ran on. */
final class FakeValkeyAclProgramRunner implements RemoteProgramRunner
{
    /** @var list<array{node: string, input: array<array-key, mixed>}> */
    public array $runs = [];

    /** The outcome to return; `failed` comes with a docker error in the log. */
    public string $outcome = 'written';

    public ?RemoteProgramFailed $failure = null;

    /** @var (Closure(): void)|null Runs during the program, such as a check of what changed before it. */
    public ?Closure $during = null;

    public function run(
        Node $node,
        RemoteProgram $program,
        array $input = [],
        #[SensitiveParameter] ?GitReadEnvironment $gitRead = null,
        ?float $timeoutSeconds = null,
        ?Closure $cancelled = null,
    ): ProgramResult {
        if ($program->name !== 'database-servers.write-valkey-acl') {
            throw new LogicException("Unexpected remote program [{$program->name}].");
        }

        $this->runs[] = ['node' => $node->name, 'input' => $input];

        if ($this->during instanceof Closure) {
            ($this->during)();
        }

        if ($this->failure instanceof RemoteProgramFailed) {
            throw $this->failure;
        }

        return new ProgramResult(
            $this->outcome,
            [],
            $this->outcome === 'failed' ? 'docker run exited 125: docker: Error response from daemon: pull access denied' : '',
        );
    }
}
