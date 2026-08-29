<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Keep one active topology attempt per issue beside every exact attempt record.
 *
 * Exact attempts live at `topologies/<issue>/<attempt>.json`; the active pointer
 * lives at `topologies/<issue>/active.json`.
 *
 * @mago-expect lint:cyclomatic-complexity,too-many-methods Every pointer and legacy-layout check fails closed.
 */
final readonly class TopologyManifestStore
{
    public const int POINTER_SCHEMA = 2;

    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
    ) {}

    public function active(string $issue): ?FeatureTopology
    {
        $attempt = $this->activeAttempt($issue);

        if ($attempt === null) {
            return null;
        }

        return (
            $this->read($issue, $attempt) ?? throw new RuntimeException(
                'The active topology attempt record is missing.',
            )
        );
    }

    public function read(string $issue, AttemptId $attempt): ?FeatureTopology
    {
        TopologyTarget::assertIssue($issue);
        $value = $this->store->read($this->attemptPath($issue, $attempt));

        if ($value === null) {
            return null;
        }

        $topology = FeatureTopology::fromArray($value);

        if ($topology->target->issue !== $issue || $topology->attempt->value !== $attempt->value) {
            throw new RuntimeException('The topology manifest identity does not match its path.');
        }

        return $topology;
    }

    /**
     * Every generation an active topology attempt pins. Standby pruning must not
     * delete a generation a live topology still runs on, so an unreadable pointer
     * or a missing attempt record fails closed instead of shrinking this list.
     *
     * @return list<string>
     */
    public function activeGenerationIds(): array
    {
        $ids = [];

        foreach ($this->activeIssues() as $issue) {
            $topology = $this->active($issue) ?? throw new RuntimeException(
                'An active topology attempt disappeared during inventory.',
            );
            $ids[] = $topology->generation->id;
        }

        return $ids;
    }

    public function writeActive(FeatureTopology $topology): void
    {
        $issue = $topology->target->issue;
        $active = $this->activeAttempt($issue);

        if ($active !== null && $active->value !== $topology->attempt->value) {
            throw new RuntimeException('The issue already has an active topology attempt.');
        }

        $this->store->write($this->attemptPath($issue, $topology->attempt), $topology->toArray());
        $this->store->write($this->pointerPath($issue), [
            'schema' => self::POINTER_SCHEMA,
            'issue' => $issue,
            'attempt' => $topology->attempt->value,
        ]);
    }

    public function forgetActive(FeatureTopology $topology): void
    {
        $issue = $topology->target->issue;
        $active = $this->activeAttempt($issue);

        if ($active !== null && $active->value !== $topology->attempt->value) {
            throw new RuntimeException('The topology is not the active topology attempt.');
        }

        // Drop the exact record first: a partial failure then leaves the pointer
        // behind, which every read refuses, instead of a silently orphaned record.
        $this->store->delete($this->attemptPath($issue, $topology->attempt));
        $this->store->delete($this->pointerPath($issue));
    }

    /** @return list<string> */
    private function activeIssues(): array
    {
        $directory = $this->paths->path('topologies');

        if (! file_exists($directory)) {
            return [];
        }

        if (! is_dir($directory) || is_link($directory) || ! is_readable($directory)) {
            throw new RuntimeException('A manifest collection cannot be inspected.');
        }

        $pointers = glob($directory.'/*/active.json');
        $legacy = glob($directory.'/*.json');

        if ($pointers === false || $legacy === false) {
            throw new RuntimeException('A manifest collection cannot be inspected.');
        }

        // A schema 1 manifest names no attempt, so its pins cannot be inventoried.
        if ($legacy !== []) {
            throw new RuntimeException(
                'A schema 1 topology manifest exists; release it with the previous harness.',
            );
        }

        sort($pointers, SORT_STRING);
        $issues = [];

        foreach ($pointers as $pointer) {
            $issue = basename(dirname($pointer));
            TopologyTarget::assertIssue($issue);
            $issues[] = $issue;
        }

        return $issues;
    }

    private function activeAttempt(string $issue): ?AttemptId
    {
        TopologyTarget::assertIssue($issue);
        $this->assertNoLegacyManifest($issue);
        $pointer = $this->store->read($this->pointerPath($issue));

        if ($pointer === null) {
            return null;
        }

        if (
            array_keys($pointer) !== ['schema', 'issue', 'attempt']
            || $pointer['schema'] !== self::POINTER_SCHEMA
            || $pointer['issue'] !== $issue
            || ! is_string($pointer['attempt'])
            || preg_match('/\A[0-9a-f]{32}\z/D', $pointer['attempt']) !== 1
        ) {
            throw new RuntimeException('The active topology pointer is invalid.');
        }

        return new AttemptId($pointer['attempt']);
    }

    /**
     * Schema 1 kept one issue-only manifest and no attempt identity. Generating one
     * here would invent cleanup ownership, so every attempt-scoped path refuses instead.
     */
    private function assertNoLegacyManifest(string $issue): void
    {
        if ($this->store->read('topologies/'.$issue.'.json') !== null) {
            throw new RuntimeException(
                'A schema 1 topology manifest exists for this issue; release it with the previous harness.',
            );
        }
    }

    private function attemptPath(string $issue, AttemptId $attempt): string
    {
        return 'topologies/'.$issue.'/'.$attempt->value.'.json';
    }

    private function pointerPath(string $issue): string
    {
        return 'topologies/'.$issue.'/active.json';
    }
}
