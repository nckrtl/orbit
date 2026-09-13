<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

interface PrivateDnsRequesterResolver
{
    public function resolve(string $sourceAddress): DnsRequester;
}
