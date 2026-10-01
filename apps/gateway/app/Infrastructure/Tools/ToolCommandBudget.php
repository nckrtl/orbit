<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use Closure;

/**
 * A per-call SSH budget for tool commands. Unset calls keep the runner's default timeout.
 */
final class ToolCommandBudget
{
    private ?float $seconds = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function limit(float $seconds, Closure $operation): mixed
    {
        $previous = $this->seconds;
        $this->seconds = $seconds;

        try {
            return $operation();
        } finally {
            $this->seconds = $previous;
        }
    }

    public function seconds(): ?float
    {
        return $this->seconds;
    }
}
