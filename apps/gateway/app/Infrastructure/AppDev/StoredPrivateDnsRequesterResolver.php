<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsRequesterResolver;

final readonly class StoredPrivateDnsRequesterResolver implements PrivateDnsRequesterResolver
{
    public function __construct(
        private FilePrivateDnsCatalogStore $store,
    ) {}

    public function resolve(string $sourceAddress): DnsRequester
    {
        $this->store->refresh();

        return $this->store->requesters()->resolve($sourceAddress);
    }
}
