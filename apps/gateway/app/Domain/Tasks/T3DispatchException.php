<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

final class T3DispatchException extends RuntimeException
{
    public function __construct(string $message = 'T3 dispatch failed.')
    {
        parent::__construct($message);
    }
}
