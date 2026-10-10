<?php

declare(strict_types=1);

namespace App\Domain\Conn;

final readonly class T3ClientSession
{
    public function __construct(
        public string $sessionId,
        public ?string $label,
        public bool $current,
    ) {}
}
