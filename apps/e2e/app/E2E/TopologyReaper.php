<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\IssueStateSnapshot;
use App\E2E\Value\ReleaseResult;
use RuntimeException;

final readonly class TopologyReaper
{
    public function __construct(
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private TopologyReleaser $releaser,
    ) {}

    /** @return list<ReleaseResult> */
    public function reap(IssueStateSnapshot $snapshot): array
    {
        $directory = $this->paths->path('leases');
        if (! is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to inspect exact topology leases.');
        }

        $results = [];
        foreach ($entries as $entry) {
            $matches = [];
            if (preg_match('/\A([A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8})\.json\z/D', $entry, $matches) !== 1) {
                continue;
            }
            $issue = $matches[1];
            $lease = $this->state->read('leases/'.$entry);
            if ($lease === null || ($lease['issue'] ?? null) !== $issue || ! is_string($lease['expires_at'] ?? null)) {
                throw new RuntimeException('A topology lease is invalid.');
            }
            $expiry = strtotime($lease['expires_at']);
            if ($expiry !== false && $expiry <= time() && $snapshot->isTerminal($issue)) {
                $results[] = $this->releaser->release($issue);
            }
        }

        return $results;
    }
}
