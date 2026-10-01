<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitRegistrationFacts
{
    public function __construct(
        public string $path,
        public string $repositoryUrl,
    ) {}
}
