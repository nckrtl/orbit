<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class LaravelRelease
{
    public function __construct(
        public string $tag,
        public string $commit,
    ) {}
}
