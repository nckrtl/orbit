<?php

declare(strict_types=1);

namespace App\Services\Dns;

interface ResolvesLocalDns
{
    public function platform(): string;

    public function available(): bool;

    /** @return array{status: string, changed: bool} */
    public function resolve(string $name, string $target, string $kind): array;

    /** @return array{status: string, changed: bool} */
    public function reset(string $name): array;
}
