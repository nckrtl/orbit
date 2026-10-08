<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceEnvironmentWriteResult
{
    private function __construct(
        public bool $confirmed,
        public ?bool $changed,
        public bool $tracked = false,
    ) {}

    public static function changed(): self
    {
        return new self(confirmed: true, changed: true);
    }

    public static function unchanged(): self
    {
        return new self(confirmed: true, changed: false);
    }

    /** The file is tracked by Git in the checkout, so it was left unchanged. */
    public static function tracked(): self
    {
        return new self(confirmed: true, changed: false, tracked: true);
    }

    public static function unconfirmed(): self
    {
        return new self(confirmed: false, changed: null);
    }
}
