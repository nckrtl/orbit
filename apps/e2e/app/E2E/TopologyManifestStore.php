<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Keep one active topology attempt per issue beside every exact attempt record.
 *
 * Exact attempts live at `topologies/<issue>/<attempt>.json`; the active pointer
 * lives at `topologies/<issue>/active.json`.
 */
final readonly class TopologyManifestStore
{
    public const int POINTER_SCHEMA = 2;

    public function __construct(
        private AtomicJsonStore $store,
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

        $this->store->delete($this->pointerPath($issue));
        $this->store->delete($this->attemptPath($issue, $topology->attempt));
    }

    private function activeAttempt(string $issue): ?AttemptId
    {
        TopologyTarget::assertIssue($issue);
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

    private function attemptPath(string $issue, AttemptId $attempt): string
    {
        return 'topologies/'.$issue.'/'.$attempt->value.'.json';
    }

    private function pointerPath(string $issue): string
    {
        return 'topologies/'.$issue.'/active.json';
    }
}
