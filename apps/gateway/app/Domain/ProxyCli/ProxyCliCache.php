<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

interface ProxyCliCache
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, ?int $seconds = null): void;

    public function forget(string $key): void;

    public function acquire(string $key, string $holder, int $seconds): bool;

    public function release(string $key, string $holder): void;
}
