<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;

final readonly class NodeSettingsPatch
{
    public function __construct(
        public bool $hasApps,
        public ?NodeStorageAppsData $apps,
    ) {}

    public function merge(?NodeSettingsData $stored): NodeSettingsData
    {
        $current = $stored ?? new NodeSettingsData;

        return new NodeSettingsData(
            apps: $this->hasApps ? $this->apps : $current->apps,
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->hasApps;
    }
}
