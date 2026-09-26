<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

/**
 * What the agent view subscriber knows about one Node's agent, built from the events on
 * `presence-node.{id}` with the rules in the Realtime events reference.
 *
 * The caller has already checked that Reverb stamped the event with `agent.{id}`. This class
 * checks the payload: sequence order, snapshot parts, and the shape of every unit. It keeps a unit
 * only when its name is an Orbit Process name, its runtime is `systemd` or `docker`, and its status
 * is a short lowercase word, and it keeps at most `MaxUnits` units. It keeps at most `MaxWorkspaces`
 * task workspaces, each with a positive Instance id, full commit ids, and non-negative counts.
 */
final class AgentChannelState
{
    public const int MaxUnits = 4096;

    public const int MaxWorkspaces = 64;

    private const string COMMIT = '/\A[0-9a-f]{40}\z/D';

    private const string UNIT_NAME = '/\Aorbit-process-[1-9][0-9]*-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D';

    private const string UNIT_STATUS = '/\A[a-z][a-z-]{0,31}\z/D';

    public int $sequence = 0;

    /** @var array<string, string> Status keyed `{runtime}:{name}`. */
    public array $units = [];

    public bool $hasSnapshot = false;

    public ?string $docker = null;

    public ?float $lastEventAt = null;

    public ?string $agentAt = null;

    /**
     * Since when, by the Gateway clock, the subscriber should ask the agent for a complete snapshot, or
     * null when it need not. It is set while agent events arrive without a complete snapshot, and when
     * the agent's sequence goes back without a membership change.
     */
    public ?float $snapshotWantedSince = null;

    /** Whether the sequence went back since the subscriber last asked for a snapshot. */
    private bool $sequenceWentBack = false;

    /**
     * Task workspaces keyed by Instance id, as the agent last reported them.
     *
     * @var array<int, array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}>
     */
    public array $workspaces = [];

    /** @var array<int, true> Instances whose `head` or `diff` changed since the last `takeChangedWorkspaces()`. */
    private array $changedWorkspaces = [];

    /** @var array{nextPart: int, parts: int, nextSequence: int, workspaces: array<int, array<string, mixed>>}|null */
    private ?array $pendingWorkspaces = null;

    /** @var array{nextPart: int, parts: int, nextSequence: int, units: array<string, string>, docker: ?string}|null */
    private ?array $pending = null;

    /**
     * Applies one agent client event and returns whether the stored view changed.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(string $event, array $data, float $receivedAt): bool
    {
        $sequence = $data['sequence'] ?? null;

        if (! is_int($sequence) || $sequence < 1) {
            return false;
        }

        if ($sequence <= $this->sequence) {
            // A new agent run starts again at 1. Without a membership change, Reverb either kept the old
            // member or a second connection publishes as the same member, so nothing here holds anymore.
            $this->reset();
            $this->sequenceWentBack = true;
        }

        $this->lastEventAt = $receivedAt;
        $this->agentAt = is_string($data['at'] ?? null) ? substr($data['at'], 0, 40) : null;

        if ($event === 'client-snapshot') {
            $this->applySnapshotPart($sequence, $data);
            $this->noteSnapshotNeed($receivedAt);

            return true;
        }

        if ($event === 'client-workspaces') {
            $this->applyWorkspacesPart($sequence, $data);
            $this->noteSnapshotNeed($receivedAt);

            return true;
        }

        $this->sequence = $sequence;
        $this->pending = null;
        $this->pendingWorkspaces = null;

        if ($event === 'client-workspace') {
            $workspace = is_array($data['workspace'] ?? null) ? self::workspace($data['workspace']) : null;

            if ($workspace !== null && (isset($this->workspaces[$workspace['instance_id']]) || count($this->workspaces) < self::MaxWorkspaces)) {
                $this->storeWorkspace($workspace);
            }

            $this->noteSnapshotNeed($receivedAt);

            return true;
        }

        if ($event === 'client-process') {
            $unit = is_array($data['unit'] ?? null) ? $this->unit($data['unit']) : null;

            if ($unit !== null && (isset($this->units[$unit[0]]) || count($this->units) < self::MaxUnits)) {
                $this->units[$unit[0]] = $unit[1];
            }
        }

        $this->noteSnapshotNeed($receivedAt);

        return true;
    }

    /** The subscriber asked every agent connection for a snapshot: wait for the next event before asking again. */
    public function snapshotRequested(): void
    {
        $this->snapshotWantedSince = null;
        $this->sequenceWentBack = false;
    }

    /** A snapshot that completes after the sequence went back can come from the second connection, so it settles nothing. */
    private function noteSnapshotNeed(float $receivedAt): void
    {
        if (! $this->hasSnapshot || $this->sequenceWentBack) {
            $this->snapshotWantedSince ??= $receivedAt;
        } else {
            $this->snapshotWantedSince = null;
        }
    }

    /** Forgets everything, as when the agent leaves the channel or the connection drops. */
    public function reset(): void
    {
        $this->sequence = 0;
        $this->units = [];
        $this->hasSnapshot = false;
        $this->docker = null;
        $this->pending = null;
        $this->lastEventAt = null;
        $this->agentAt = null;
        $this->snapshotWantedSince = null;
        $this->sequenceWentBack = false;
        $this->workspaces = [];
        $this->changedWorkspaces = [];
        $this->pendingWorkspaces = null;
    }

    /**
     * The Instances whose `head` or diff counts changed since the last call.
     *
     * @return list<int>
     */
    public function takeChangedWorkspaces(): array
    {
        $changed = array_keys($this->changedWorkspaces);
        $this->changedWorkspaces = [];

        return $changed;
    }

    /**
     * A workspace entry exactly as the Gateway keeps it, or null when any field is malformed.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}|null
     */
    public static function workspace(array $entry): ?array
    {
        $instanceId = $entry['instance_id'] ?? null;
        $base = $entry['base'] ?? null;
        $start = $entry['start'] ?? null;
        $branch = $entry['branch'] ?? null;
        $head = $entry['head'] ?? null;
        $dirty = $entry['dirty'] ?? null;
        $commits = $entry['commits'] ?? null;
        $diff = $entry['diff'] ?? null;

        if (
            ! is_int($instanceId) || $instanceId < 1
            || ! is_string($base) || $base === '' || strlen($base) > 255
            || ($start !== null && (! is_string($start) || preg_match(self::COMMIT, $start) !== 1))
            || ($branch !== null && (! is_string($branch) || $branch === '' || strlen($branch) > 255 || preg_match('/[\x00-\x1f\x7f]/', $branch) === 1))
            || ($head !== null && (! is_string($head) || preg_match(self::COMMIT, $head) !== 1))
            || ($dirty !== null && ! is_bool($dirty))
            || ($commits !== null && (! is_int($commits) || $commits < 0))
        ) {
            return null;
        }

        if ($diff !== null) {
            if (! is_array($diff)) {
                return null;
            }

            $counts = [];

            foreach (['files', 'added', 'removed'] as $field) {
                $count = $diff[$field] ?? null;

                if (! is_int($count) || $count < 0) {
                    return null;
                }

                $counts[$field] = $count;
            }

            $truncated = $diff['truncated'] ?? false;

            if (! is_bool($truncated)) {
                return null;
            }

            $diff = [...$counts, 'truncated' => $truncated];
        }

        return [
            'instance_id' => $instanceId, 'base' => $base, 'start' => $start, 'branch' => $branch,
            'head' => $head, 'dirty' => $dirty, 'commits' => $commits, 'diff' => $diff,
        ];
    }

    /** @param array<string, mixed> $data */
    private function applySnapshotPart(int $sequence, array $data): void
    {
        $part = $data['part'] ?? null;
        $parts = $data['parts'] ?? null;
        $units = $data['units'] ?? null;
        $docker = in_array($data['docker'] ?? null, ['available', 'absent'], strict: true) ? $data['docker'] : null;

        if (! is_int($part) || ! is_int($parts) || $part < 1 || $parts < 1 || $part > $parts || ! is_array($units)) {
            $this->pending = null;

            return;
        }

        if ($part === 1) {
            $this->pending = ['nextPart' => 1, 'parts' => $parts, 'nextSequence' => $sequence, 'units' => [], 'docker' => $docker];
        }

        $pending = $this->pending;

        if ($pending === null || $pending['parts'] !== $parts || $pending['nextPart'] !== $part || $pending['nextSequence'] !== $sequence) {
            $this->pending = null;

            return;
        }

        foreach ($units as $entry) {
            $unit = is_array($entry) ? $this->unit($entry) : null;

            if ($unit !== null && count($pending['units']) < self::MaxUnits) {
                $pending['units'][$unit[0]] = $unit[1];
            }
        }

        $this->sequence = $sequence;
        $pending['nextPart']++;
        $pending['nextSequence']++;

        if ($pending['nextPart'] > $pending['parts']) {
            $this->units = $pending['units'];
            $this->docker = $pending['docker'];
            $this->hasSnapshot = true;
            $this->pending = null;

            return;
        }

        $this->pending = $pending;
    }

    /** @param array<string, mixed> $data */
    private function applyWorkspacesPart(int $sequence, array $data): void
    {
        $this->pending = null;
        $part = $data['part'] ?? null;
        $parts = $data['parts'] ?? null;
        $entries = $data['workspaces'] ?? null;

        if (! is_int($part) || ! is_int($parts) || $part < 1 || $parts < 1 || $part > $parts || ! is_array($entries)) {
            $this->pendingWorkspaces = null;

            return;
        }

        if ($part === 1) {
            $this->pendingWorkspaces = ['nextPart' => 1, 'parts' => $parts, 'nextSequence' => $sequence, 'workspaces' => []];
        }

        $pending = $this->pendingWorkspaces;

        if ($pending === null || $pending['parts'] !== $parts || $pending['nextPart'] !== $part || $pending['nextSequence'] !== $sequence) {
            $this->pendingWorkspaces = null;

            return;
        }

        foreach ($entries as $entry) {
            $workspace = is_array($entry) ? self::workspace($entry) : null;

            if ($workspace !== null && count($pending['workspaces']) < self::MaxWorkspaces) {
                $pending['workspaces'][$workspace['instance_id']] = $workspace;
            }
        }

        $this->sequence = $sequence;
        $pending['nextPart']++;
        $pending['nextSequence']++;

        if ($pending['nextPart'] <= $pending['parts']) {
            $this->pendingWorkspaces = $pending;

            return;
        }

        $this->pendingWorkspaces = null;

        foreach (array_diff_key($this->workspaces, $pending['workspaces']) as $instanceId => $removed) {
            unset($this->workspaces[$instanceId]);
        }

        foreach ($pending['workspaces'] as $workspace) {
            /** @var array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null} $workspace */
            $this->storeWorkspace($workspace);
        }
    }

    /** @param array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null} $workspace */
    private function storeWorkspace(array $workspace): void
    {
        $previous = $this->workspaces[$workspace['instance_id']] ?? null;

        if ($previous === null || $previous['head'] !== $workspace['head'] || $previous['diff'] !== $workspace['diff'] || $previous['base'] !== $workspace['base']) {
            $this->changedWorkspaces[$workspace['instance_id']] = true;
        }

        $this->workspaces[$workspace['instance_id']] = $workspace;
    }

    /**
     * @param  array<array-key, mixed>  $unit
     * @return array{0: string, 1: string}|null
     */
    private function unit(array $unit): ?array
    {
        $name = $unit['name'] ?? null;
        $runtime = $unit['runtime'] ?? null;
        $status = $unit['runtime_status'] ?? null;

        if (
            ! is_string($name) || strlen($name) > 200 || preg_match(self::UNIT_NAME, $name) !== 1
            || ! in_array($runtime, ['systemd', 'docker'], strict: true)
            || ! is_string($status) || preg_match(self::UNIT_STATUS, $status) !== 1
        ) {
            return null;
        }

        return ["{$runtime}:{$name}", $status];
    }
}
