<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final readonly class DevelopmentInstanceCopyInspection
{
    public function __construct(public string $head) {}
}
