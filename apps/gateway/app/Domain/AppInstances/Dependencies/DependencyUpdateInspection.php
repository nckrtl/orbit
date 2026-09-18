<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use InvalidArgumentException;

final readonly class DependencyUpdateInspection
{
    private function __construct(
        public bool $present,
        public ?string $errorCode,
        public ?string $toolPath,
    ) {
        if ($errorCode === '') {
            throw new InvalidArgumentException('A failed dependency inspection requires an error code.');
        }
    }

    public static function absent(): self
    {
        return new self(false, null, null);
    }

    public static function ready(?string $toolPath = null): self
    {
        return new self(true, null, $toolPath);
    }

    public static function failed(string $errorCode): self
    {
        return new self(false, $errorCode, null);
    }

    public function blocked(): bool
    {
        return $this->errorCode !== null;
    }
}
