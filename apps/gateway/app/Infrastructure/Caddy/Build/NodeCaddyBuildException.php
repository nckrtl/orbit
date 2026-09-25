<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Processes\CommandResult;
use RuntimeException;
use Throwable;

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

    /**
     * The build details of the first failed build in an exception chain, for an API error envelope that
     * wraps it in the caller's own error code.
     *
     * @return array{node?: string, stage?: string, message?: string}
     */
    public static function detailsIn(?Throwable $exception): array
    {
        while ($exception instanceof Throwable) {
            if ($exception instanceof self) {
                return $exception->details();
            }

            $exception = $exception->getPrevious();
        }

        return [];
    }

    /** @return array{node: string, stage: string, message: string} */
    public function details(): array
    {
        return ['node' => $this->nodeName, 'stage' => $this->stage, 'message' => $this->detail];
    }
}
