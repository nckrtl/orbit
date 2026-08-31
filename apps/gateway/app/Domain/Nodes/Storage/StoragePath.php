<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,too-many-methods Directory-boundary path algebra stays on one value object. */
final readonly class StoragePath
{
    private function __construct(
        public string $value,
    ) {}

    public static function tryParse(string $path): ?self
    {
        if ($path === '' || $path === '/' || ! str_starts_with($path, '/')) {
            return null;
        }

        if (str_ends_with($path, '/') || str_contains($path, '//')) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return new self($path);
    }

    public static function parse(string $path): self
    {
        $parsed = self::tryParse($path);

        if ($parsed instanceof self) {
            return $parsed;
        }

        throw new InvalidArgumentException("Storage path [{$path}] is not a normalized absolute path.");
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function contains(self $other): bool
    {
        return str_starts_with($other->value, $this->value.'/');
    }

    public function isInside(self $other): bool
    {
        return $other->contains($this);
    }

    public function overlaps(self $other): bool
    {
        return $this->equals($other) || $this->contains($other) || $this->isInside($other);
    }

    public function parent(): ?self
    {
        $parent = dirname($this->value);

        return self::tryParse($parent);
    }

    public function append(string ...$segments): self
    {
        $path = $this->value;

        foreach ($segments as $segment) {
            $path .= "/{$segment}";
        }

        return self::parse($path);
    }

    public function hasSuffix(string ...$segments): bool
    {
        $suffix = '/'.implode('/', $segments);

        return str_ends_with($this->value, $suffix) && strlen($this->value) > strlen($suffix);
    }

    public function stripSuffix(string ...$segments): self
    {
        if (! $this->hasSuffix(...$segments)) {
            throw new InvalidArgumentException("Path [{$this->value}] does not have the required suffix.");
        }

        $suffix = '/'.implode('/', $segments);
        $root = substr($this->value, 0, -strlen($suffix));

        return self::parse($root);
    }
}
