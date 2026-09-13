<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

final readonly class PrivateDnsAnswer
{
    /**
     * @param  list<string>  $addresses
     */
    private function __construct(
        public array $addresses,
        public bool $authoritative,
    ) {}

    public static function empty(): self
    {
        return new self([], false);
    }

    public static function a(string $address): self
    {
        return new self([$address], true);
    }

    /**
     * @param  list<string>  $addresses
     */
    public static function records(array $addresses): self
    {
        return new self($addresses, $addresses !== []);
    }
}
