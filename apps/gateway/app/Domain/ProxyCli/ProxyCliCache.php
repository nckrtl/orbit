<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

interface ProxyCliCache
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, ?int $seconds = null): void;
}
