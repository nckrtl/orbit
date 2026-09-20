<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliCache;

final class ArrayProxyCliCache implements ProxyCliCache
{
    /** @var array<string, array{value: string, expires: int|null, holder?: string}> */
    private array $items = [];

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['expires'] !== null && $item['expires'] <= time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires' => $seconds === null ? null : time() + $seconds,
        ];
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    public function acquire(string $key, string $holder, int $seconds): bool
    {
        $current = $this->get($key);

        if ($current !== null && $current !== $holder) {
            return false;
        }

        $this->items[$key] = [
            'value' => $holder,
            'expires' => time() + $seconds,
            'holder' => $holder,
        ];

        return true;
    }

    public function release(string $key, string $holder): void
    {
        $item = $this->items[$key] ?? null;

        if (($item['value'] ?? null) === $holder) {
            unset($this->items[$key]);
        }
    }
}
