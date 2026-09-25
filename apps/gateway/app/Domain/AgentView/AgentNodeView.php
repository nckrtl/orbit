<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

use App\Domain\Processes\ProcessRuntime;

/**
 * The Gateway's view of one Node agent at the moment a reader asked for it.
 *
 * Units are keyed `{runtime}:{name}`, for example `systemd:orbit-process-42-web`, exactly as the
 * agent reported them. Only a fresh view answers; every other view answers null so the reader
 * falls back to Prometheus or SSH.
 *
 * `logs` says whether the agent is a member of the Node's log channel, `presence-node-logs.{id}`,
 * which agents from 0.3.0 join to serve live log streams (ADR 0153). `agentVersion` is the version the
 * agent reported when it joined, or null when the Gateway does not know it.
 */
final readonly class AgentNodeView
{
    /**
     * @param  array<string, string>  $units
     * @param  array<int, array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}>  $workspaces  Task workspaces keyed by Instance id.
     */
    public function __construct(
        public int $nodeId,
        public AgentViewFreshness $freshness,
        public array $units = [],
        public ?string $docker = null,
        public ?float $receivedAt = null,
        public array $workspaces = [],
        public bool $logs = false,
        public ?string $agentVersion = null,
    ) {}

    public static function missing(int $nodeId): self
    {
        return new self($nodeId, AgentViewFreshness::Missing);
    }

    public function isFresh(): bool
    {
        return $this->freshness === AgentViewFreshness::Fresh;
    }

    /**
     * The unit's runtime status, or null when the view cannot answer.
     *
     * In a fresh view a systemd unit the agent does not list is `inactive`, and a container it
     * does not list is `exited`. The view does not answer for Docker while the agent reports
     * Docker as absent.
     */
    public function status(ProcessRuntime $runtime, string $name): ?string
    {
        if (! $this->answers($runtime)) {
            return null;
        }

        return $this->units[$runtime->value.':'.$name]
            ?? ($runtime === ProcessRuntime::Systemd ? 'inactive' : 'exited');
    }

    /** Whether the agent lists this exact unit or container, or null when the view cannot answer. */
    public function lists(ProcessRuntime $runtime, string $name): ?bool
    {
        if (! $this->answers($runtime)) {
            return null;
        }

        return isset($this->units[$runtime->value.':'.$name]);
    }

    /**
     * The Git state the agent last reported for a task checkout, or null when the view is not fresh
     * or the agent does not report that Instance (ADR 0151).
     *
     * @return array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}|null
     */
    public function workspace(int $instanceId): ?array
    {
        return $this->isFresh() ? ($this->workspaces[$instanceId] ?? null) : null;
    }

    private function answers(ProcessRuntime $runtime): bool
    {
        return $this->isFresh()
            && ($runtime !== ProcessRuntime::Docker || $this->docker === 'available');
    }
}
