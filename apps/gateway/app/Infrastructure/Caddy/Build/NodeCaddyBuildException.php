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
    /** The detail of every stage is at most this many bytes of UTF-8, so API and CLI details keep it whole. */
    public const int MaxDetailBytes = 2000;

    public readonly string $detail;

    public function __construct(
        public readonly string $nodeName,
        public readonly string $stage,
        string $detail,
    ) {
        $this->detail = self::bounded($detail);

        parent::__construct("The Caddy build for Node [{$nodeName}] failed at stage [{$stage}]: {$this->detail}");
    }

    /** Cuts a detail to MaxDetailBytes on a character boundary and marks the cut with an ellipsis. */
    public static function bounded(string $detail): string
    {
        $detail = mb_scrub($detail, 'UTF-8');

        if (strlen($detail) <= self::MaxDetailBytes) {
            return $detail;
        }

        return mb_strcut($detail, 0, self::MaxDetailBytes - strlen('…'), 'UTF-8').'…';
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
