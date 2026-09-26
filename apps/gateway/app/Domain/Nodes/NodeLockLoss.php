<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/**
 * The failure of a command whose operation no longer holds one of its per-Node locks. Every wrapper that
 * reports an operation's specific code reports `node.lock_lost` when this failure is in its chain.
 */
final class NodeLockLoss
{
    public const string ErrorCode = 'node.lock_lost';

    public static function exception(string $lock): ResourceOperationException
    {
        return new ResourceOperationException(
            self::ErrorCode,
            "The operation's Node lock [{$lock}] expired before it could be renewed.",
            409,
        );
    }

    /** Whether the exception or one of its previous exceptions is a lost Node lock. */
    public static function in(?Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ResourceOperationException && $current->errorCode === self::ErrorCode) {
                return true;
            }
        }

        return false;
    }
}
