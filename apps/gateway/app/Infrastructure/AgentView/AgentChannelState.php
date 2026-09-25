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
 * is a short lowercase word, and it keeps at most `MaxUnits` units.
 */
final class AgentChannelState
{
    public const int MaxUnits = 4096;

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
        } else {
            $this->sequence = $sequence;
            $this->pending = null;

            if ($event === 'client-process') {
                $unit = is_array($data['unit'] ?? null) ? $this->unit($data['unit']) : null;

                if ($unit !== null && (isset($this->units[$unit[0]]) || count($this->units) < self::MaxUnits)) {
                    $this->units[$unit[0]] = $unit[1];
                }
            }
        }

        if (! $this->hasSnapshot || $this->sequenceWentBack) {
            $this->snapshotWantedSince ??= $receivedAt;
        } else {
            $this->snapshotWantedSince = null;
        }

        return true;
    }

    /** The subscriber asked every agent connection for a snapshot: wait for the next event before asking again. */
    public function snapshotRequested(): void
    {
        $this->snapshotWantedSince = null;
        $this->sequenceWentBack = false;
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
