<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Keep one release receipt per exact attempt at `evidence/releases/<issue>/<attempt>.json`.
 *
 * @mago-expect lint:cyclomatic-complexity Every receipt read and inventory step fails closed.
 */
final readonly class ReleaseReceiptStore
{
    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
    ) {}

    public function write(ReleaseResult $result): void
    {
        $this->store->write($this->path($result->issue, $result->attempt), $result->toArray());
    }

    public function read(string $issue, AttemptId $attempt): ?ReleaseResult
    {
        TopologyTarget::assertIssue($issue);
        $value = $this->store->read($this->path($issue, $attempt));
        if ($value === null) {
            return null;
        }

        $result = ReleaseResult::fromArray($value);
        if ($result->issue !== $issue || $result->attempt->value !== $attempt->value) {
            throw new RuntimeException('The release receipt identity does not match its path.');
        }

        return $result;
    }

    /** The newest verified discovery receipt of the issue, or null when none was released. */
    public function latestDiscovery(string $issue): ?ReleaseResult
    {
        $latest = null;
        foreach ($this->receipts($issue) as $receipt) {
            if ($receipt->purpose !== AttemptPurpose::Discovery || $receipt->verifiedAbsent === []) {
                continue;
            }
            if (
                $latest === null
                || strcmp($receipt->releasedAt, $latest->releasedAt) > 0
                || $receipt->releasedAt === $latest->releasedAt
                && strcmp($receipt->attempt->value, $latest->attempt->value) > 0
            ) {
                $latest = $receipt;
            }
        }

        return $latest;
    }

    /** @return list<ReleaseResult> */
    private function receipts(string $issue): array
    {
        TopologyTarget::assertIssue($issue);
        $directory = $this->paths->path('evidence/releases/'.$issue);
        if (! file_exists($directory)) {
            return [];
        }
        if (! is_dir($directory) || is_link($directory) || ! is_readable($directory)) {
            throw new RuntimeException('The release receipt collection cannot be inspected.');
        }
        $files = glob($directory.'/*.json');
        if ($files === false) {
            throw new RuntimeException('The release receipt collection cannot be inspected.');
        }
        sort($files, SORT_STRING);

        $receipts = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/\A[0-9a-f]{32}\z/D', $name) !== 1) {
                throw new RuntimeException('A release receipt path is invalid.');
            }
            $receipts[] = $this->read($issue, new AttemptId($name)) ?? throw new RuntimeException(
                'A release receipt disappeared during inventory.',
            );
        }

        return $receipts;
    }

    private function path(string $issue, AttemptId $attempt): string
    {
        return 'evidence/releases/'.$issue.'/'.$attempt->value.'.json';
    }
}
