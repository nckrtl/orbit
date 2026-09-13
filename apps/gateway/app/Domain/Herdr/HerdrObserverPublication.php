<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

final readonly class HerdrObserverPublication
{
    public function __construct(
        public string $url,
        public bool $published,
        public ?string $error = null,
    ) {}
}
