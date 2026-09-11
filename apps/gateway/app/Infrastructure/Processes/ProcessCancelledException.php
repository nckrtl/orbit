<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use RuntimeException;

final class ProcessCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The process was cancelled.');
    }
}
