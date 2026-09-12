<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use Throwable;

final class SyncCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:sync {issue} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Apply Gateway migrations and verify mounted discovery readiness';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $request = $this->request();
            $topology = $acquirer->sync($request);
            $this->log($request, 'attempt='.$topology->attempt->value.' ok');
            $this->outputJson([
                'state' => 'ready',
                'issue' => $request->issue,
                'attempt_id' => $topology->attempt->value,
                'source' => $topology->source->toArray(),
            ], 'ready '.$topology->attempt->value);

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
