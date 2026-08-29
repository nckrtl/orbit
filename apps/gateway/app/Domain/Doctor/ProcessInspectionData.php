<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use InvalidArgumentException;

final readonly class ProcessInspectionData
{
    public function __construct(
        public bool $present,
        public ?ProcessInspectionStatus $status,
    ) {
        if ($present !== ($status !== null)) {
            throw new InvalidArgumentException('A present process needs a bounded status.');
        }
    }
}
