<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Sqlite;

final readonly class SqliteSeedResult
{
    private function __construct(
        public bool $confirmed,
        public ?bool $changed,
    ) {}

    public static function changed(): self
    {
        return new self(true, true);
    }

    public static function unchanged(): self
    {
        return new self(true, false);
    }

    public static function unconfirmed(): self
    {
        return new self(false, null);
    }
}
