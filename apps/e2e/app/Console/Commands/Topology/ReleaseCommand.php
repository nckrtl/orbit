<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyReleaser;
use Throwable;

final class ReleaseCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:release {issue} '.self::WORKTREE_OPTION.' {--json}';
    #[\Override]
    protected $description = 'Release the live topology of the issue and sweep orphaned harness networks';

    public function handle(TopologyReleaser $releaser): int
    {
        try {
            $request = $this->request();
            $result = $releaser->release($request);
            $this->log($request, 'attempt='.$result['attempt_id'].' ok');
            $this->outputJson($result, 'released '.$result['attempt_id']);

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
