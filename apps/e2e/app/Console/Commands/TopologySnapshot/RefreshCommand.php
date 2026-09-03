<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologySnapshotRefresher;
use Throwable;

final class RefreshCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-snapshot:refresh
        {--main-sha=}
        {--allow-cold : Permit initial construction from the generic base image}
        {--json}';
    #[\Override]
    protected $description = 'Refresh and promote the topology snapshot generation';

    public function handle(TopologySnapshotRefresher $refresher): int
    {
        try {
            $sha = $this->option('main-sha');
            if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new \InvalidArgumentException('The exact main SHA is required.');
            }
            $result = $refresher->request($sha, (bool) $this->option('allow-cold'));
            $this->line($this->option('json') ? json_encode($result->toArray(), JSON_THROW_ON_ERROR) : $result->state);

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
