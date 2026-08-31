<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

final class NodeSettingsData extends Data
{
    public function __construct(
        public ?AppsSettingsData $apps = null,
    ) {}

    public function appsPath(): ?string
    {
        $path = $this->apps?->path;

        return is_string($path) ? $path : null;
    }

    public function isEmpty(): bool
    {
        return $this->appsPath() === null;
    }
}
