<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use RuntimeException;

/** A self-update step that failed, with a stable error code and a message safe to print. */
final class SelfUpdateFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
