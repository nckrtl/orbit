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
 */
final readonly class AgentNodeView
{
    /** @param array<string, string> $units */
    public function __construct(
        public int $nodeId,
        public AgentViewFreshness $freshness,
        public array $units = [],
        public ?string $docker = null,
        public ?float $receivedAt = null,
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

    private function answers(ProcessRuntime $runtime): bool
    {
        return $this->isFresh()
            && ($runtime !== ProcessRuntime::Docker || $this->docker === 'available');
    }
}
