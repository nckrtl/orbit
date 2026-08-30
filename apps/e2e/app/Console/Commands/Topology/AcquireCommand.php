<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class AcquireCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:acquire {issue} {worktree} {--json}';
    #[\Override]
    protected $description = 'Acquire a fresh discovery topology on the mounted worktree';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $request = new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree'));
            $topology = $acquirer->acquire($request);
            $this->log($request, 'attempt='.$topology->attempt->value.' ok');
            $this->outputJson(
                [
                    'state' => $topology->purpose->value,
                    'issue' => $request->issue,
                    'attempt_id' => $topology->attempt->value,
                    'worktree' => $request->worktree,
                    'topology' => $topology->toArray(),
                ],
                $topology->purpose->value.' '.$topology->attempt->value,
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
