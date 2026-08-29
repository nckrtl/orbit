<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final class ToolInspectionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(message: '');
    }
}
