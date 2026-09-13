<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

interface PrivateDnsUpstream
{
    public function resolve(string $message): string;
}
