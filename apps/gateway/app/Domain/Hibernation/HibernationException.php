<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use RuntimeException;

final class HibernationException extends RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public int $status = 502,
    ) {
        parent::__construct($message);
    }
}
