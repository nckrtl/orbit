<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

final readonly class DnsRequester
{
    private function __construct(
        public DnsRequesterIdentity $identity,
        public ?int $nodeId,
        public string $sourceAddress,
    ) {}

    public static function registered(int $nodeId, string $sourceAddress): self
    {
        return new self(DnsRequesterIdentity::Registered, $nodeId, $sourceAddress);
    }

    public static function unidentified(string $sourceAddress): self
    {
        return new self(DnsRequesterIdentity::Unidentified, null, $sourceAddress);
    }

    public function isRegistered(): bool
    {
        return $this->identity === DnsRequesterIdentity::Registered;
    }

    public function cacheKey(): string
    {
        if ($this->identity === DnsRequesterIdentity::Registered && $this->nodeId !== null) {
            return 'node:'.$this->nodeId;
        }

        return 'unidentified:'.$this->sourceAddress;
    }
}
