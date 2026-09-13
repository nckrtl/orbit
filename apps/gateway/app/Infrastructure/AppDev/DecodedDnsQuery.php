<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsQuestion;

final readonly class DecodedDnsQuery
{
    public function __construct(
        public int $id,
        public int $flags,
        public DnsQuestion $question,
        public ?string $contentIdentity,
    ) {}
}
