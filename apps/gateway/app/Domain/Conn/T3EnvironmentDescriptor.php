<?php

declare(strict_types=1);

namespace App\Domain\Conn;

final readonly class T3EnvironmentDescriptor
{
    public function __construct(
        public string $environmentId,
        public string $label,
        public string $serverVersion,
    ) {}
}
