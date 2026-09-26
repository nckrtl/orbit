<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;

/** Records each command it is asked to run and succeeds without running it. */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $ran = [];

    /** @var (Closure(): void)|null */
    public ?Closure $during = null;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->ran[] = $invocation->arguments;

        if ($this->during !== null) {
            ($this->during)();
        }

        return new CommandResult(0, '', '', 0, false);
    }
}
