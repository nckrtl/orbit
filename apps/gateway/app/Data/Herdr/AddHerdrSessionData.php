<?php

declare(strict_types=1);

namespace App\Data\Herdr;

final readonly class AddHerdrSessionData
{
    public function __construct(
        public int $nodeId,
        public string $session,
        public string $user,
        public bool $publishObserver,
    ) {}
}
