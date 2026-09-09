<?php

declare(strict_types=1);

namespace App\Services\Git;

interface GitRegistrationDiscovery
{
    public function inspect(string $path): ?GitRegistrationFacts;
}
