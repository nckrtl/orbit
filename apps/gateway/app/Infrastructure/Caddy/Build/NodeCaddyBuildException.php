<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Processes\CommandResult;
use RuntimeException;

/**
 * A Node Caddy build that changed nothing on the Node. The calling operation keeps its own error
 * code and records these details: the Node, the failed stage, and Caddy's or the renderer's message.
 */
final class NodeCaddyBuildException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeName,
        public readonly string $stage,
        public readonly string $detail,
    ) {
        parent::__construct("The Caddy build for Node [{$nodeName}] failed at stage [{$stage}]: {$detail}");
    }

    /** A failed result that carries this message, for a caller that records a step's standard error. */
    public function result(): CommandResult
    {
        return new CommandResult(exitCode: 1, stdout: '', stderr: $this->getMessage().PHP_EOL, durationMs: 0, truncated: false);
    }

    /** @return array{node: string, stage: string, message: string} */
    public function details(): array
    {
        return ['node' => $this->nodeName, 'stage' => $this->stage, 'message' => $this->detail];
    }
}
