<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

/** A MySQL, PostgreSQL, or Redis attachment copied with a development Instance. */
final readonly class InstanceSharedDatabaseResponse
{
    public function __construct(
        public string $slug,
        public string $driver,
    ) {}

    /** @return array{slug: string, driver: string} */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'driver' => $this->driver];
    }
}
