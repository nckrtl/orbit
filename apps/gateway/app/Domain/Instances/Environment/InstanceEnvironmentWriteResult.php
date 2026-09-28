<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceEnvironmentWriteResult
{
    private function __construct(
        public bool $confirmed,
        public ?bool $changed,
    ) {}

    public static function changed(): self
    {
        return new self(confirmed: true, changed: true);
    }

    public static function unchanged(): self
    {
        return new self(confirmed: true, changed: false);
    }

    public static function unconfirmed(): self
    {
        return new self(confirmed: false, changed: null);
    }
}
