<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use Throwable;

final class SyncCommand extends E2ECommand
{
    /** The note a quick sync prints, because it proves less than a full sync. */
    public const string QUICK_NOTE =
        'Readiness was not verified, and the recorded source binding is unchanged. Run sync without --quick for verified readiness.';

    #[\Override]
    protected $signature = 'topology:sync {issue} '
        .self::WORKTREE_OPTION
        .' {--quick : Prove the mount, install guest helpers, and migrate the Gateway without verifying readiness}'
        .' {--json}';

    #[\Override]
    protected $description = 'Apply Gateway migrations and verify mounted discovery readiness';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $request = $this->request();
            $quick = (bool) $this->option('quick');
            $topology = $acquirer->sync($request, $quick);
            if ($quick) {
                $this->log($request, 'attempt='.$topology->attempt->value.' quick ok, readiness not verified');
                $this->outputJson([
                    'state' => 'synced',
                    'verified' => false,
                    'issue' => $request->issue,
                    'attempt_id' => $topology->attempt->value,
                    'note' => self::QUICK_NOTE,
                ], 'synced '.$topology->attempt->value." without readiness verification\n".self::QUICK_NOTE);

                return self::SUCCESS;
            }
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
