<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

final readonly class DnsQuestion
{
    public function __construct(
        public string $name,
        public DnsRecordType $type,
        public int $class = 1,
    ) {}

    public function normalizedName(): string
    {
        return rtrim(strtolower($this->name), '.');
    }
}
