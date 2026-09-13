<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

final readonly class HerdrLivePane
{
    public function __construct(
        public string $pane,
        public string $terminal,
        public bool $live,
    ) {}
}
