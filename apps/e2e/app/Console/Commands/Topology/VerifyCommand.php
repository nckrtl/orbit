<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use Throwable;

final class VerifyCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:verify {issue} '.self::WORKTREE_OPTION.' {--json}';
    #[\Override]
    protected $description = 'Verify the live topology of the issue';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $request = $this->request();
            $topology = $acquirer->verify($request);
            $this->log($request, 'attempt='.$topology->attempt->value.' ok');
            $this->outputJson([
                'state' => 'verified',
                'issue' => $request->issue,
                'attempt_id' => $topology->attempt->value,
                'verification' => $topology->verification->toArray(),
            ], 'verified '.$topology->attempt->value);

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
