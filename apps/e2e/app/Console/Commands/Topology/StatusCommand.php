<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use Throwable;

/** Read-only: reports the live attempt of an issue from its worktree state. */
final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:status {issue} '.self::WORKTREE_OPTION.' {--json}';
    #[\Override]
    protected $description = 'Report the live topology attempt of the issue without touching infrastructure';

    public function handle(): int
    {
        try {
            $request = $this->request();
            $state = $this->state($request);
            if (! $state->hasAttempt()) {
                $this->outputJson([
                    'state' => 'absent',
                    'issue' => $request->issue,
                    'worktree' => $request->worktree,
                    'proof' => $state->proof(),
                ], 'absent');

                return self::SUCCESS;
            }
            $attempt = $state->attempt();
            $topology = $state->topology();
            $this->outputJson(
                [
                    'state' => $attempt['purpose'],
                    'issue' => $request->issue,
                    'attempt_id' => $attempt['attempt_id'],
                    'worktree' => $request->worktree,
                    'acquired_at' => $attempt['acquired_at'],
                    'proved' => $state->isProved(),
                    'topology' => $topology?->toArray(),
                    'proof' => $state->proof(),
                ],
                $attempt['purpose'].' '.$attempt['attempt_id'].($state->isProved() ? ' proved' : ''),
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
