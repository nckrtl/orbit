<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\IssueStateSnapshot;
use App\E2E\Value\ReleaseResult;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity Lease expiry and cleanup retain one auditable recovery boundary. */
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

        $expired = [];
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
            $expiresAt = $lease['expires_at'];
            $parsedExpiry = \DateTimeImmutable::createFromFormat(
                '!Y-m-d\\TH:i:s\\Z',
                $expiresAt,
                new \DateTimeZone('UTC'),
            );
            if (
                preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $expiresAt) !== 1
                || ! $parsedExpiry instanceof \DateTimeImmutable
                || $parsedExpiry->format('Y-m-d\\TH:i:s\\Z') !== $expiresAt
            ) {
                throw new RuntimeException('A topology lease is invalid.');
            }
            if ($parsedExpiry->getTimestamp() <= time() && $snapshot->isTerminal($issue)) {
                $expired[] = $issue;
            }
        }

        $results = [];
        foreach ($expired as $issue) {
            try {
                $results[] = $this->releaser->release($issue);
                $this->state->delete('reaping-failures/'.$issue.'.json');
            } catch (\Throwable $exception) {
                // A broken topology must not prevent cleanup of later issues.
                $this->state->write('reaping-failures/'.$issue.'.json', [
                    'schema' => 1,
                    'issue' => $issue,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
