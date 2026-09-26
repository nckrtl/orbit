<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/**
 * One open live log stream: one viewer following one Instance or Process log (ADR 0153).
 *
 * `nodeId` is the Node that serves the record and whose agent reads the source. `viewerNodeId` is
 * the WireGuard peer that opened the stream; only that peer may renew or close it.
 *
 * A stream starts inactive. The viewer's first renewal, sent once its channel subscription
 * succeeded, activates it; only then does the agent read the source. So no line reaches the
 * channel before the viewer listens.
 */
final readonly class LogStream
{
    public const string ID = '/\A[0-9a-f]{32}\z/D';

    public function __construct(
        public string $id,
        public LogStreamRecordType $recordType,
        public int $recordId,
        public int $nodeId,
        public int $viewerNodeId,
        public LogStreamSource $source,
        public int $lines,
        public float $expiresAt,
        public bool $active = false,
    ) {}

    public function channel(): string
    {
        return 'private-log-stream.'.$this->id;
    }

    public function isExpired(float $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function withExpiry(float $expiresAt): self
    {
        return new self($this->id, $this->recordType, $this->recordId, $this->nodeId, $this->viewerNodeId, $this->source, $this->lines, $expiresAt, $this->active);
    }

    public function activated(): self
    {
        return new self($this->id, $this->recordType, $this->recordId, $this->nodeId, $this->viewerNodeId, $this->source, $this->lines, $this->expiresAt, true);
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $id = $stored['id'] ?? null;
        $type = is_string($stored['record_type'] ?? null) ? LogStreamRecordType::tryFrom($stored['record_type']) : null;
        $source = is_array($stored['source'] ?? null) ? LogStreamSource::fromArray($stored['source']) : null;

        if (
            ! is_string($id) || preg_match(self::ID, $id) !== 1 || $type === null || $source === null
            || ! is_int($stored['record_id'] ?? null) || ! is_int($stored['node_id'] ?? null)
            || ! is_int($stored['viewer_node_id'] ?? null) || ! is_int($stored['lines'] ?? null)
            || ! is_numeric($stored['expires_at'] ?? null)
        ) {
            return null;
        }

        return new self($id, $type, $stored['record_id'], $stored['node_id'], $stored['viewer_node_id'], $source, $stored['lines'], (float) $stored['expires_at'], ($stored['active'] ?? false) === true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'record_type' => $this->recordType->value,
            'record_id' => $this->recordId,
            'node_id' => $this->nodeId,
            'viewer_node_id' => $this->viewerNodeId,
            'source' => $this->source->toArray(),
            'lines' => $this->lines,
            'expires_at' => $this->expiresAt,
            'active' => $this->active,
        ];
    }
}
