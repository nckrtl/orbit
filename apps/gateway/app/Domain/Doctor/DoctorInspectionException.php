<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final class DoctorInspectionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(message: '');
    }
}
