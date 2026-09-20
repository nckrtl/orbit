<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Node;

interface AnalyticsRoleSettingsRepository
{
    public function find(Node $node): ?AnalyticsRoleSettings;

    public function store(Node $node, AnalyticsRoleSettings $settings): void;

    /** The pinned Plausible version: the one `analytics:update` stored, or the default Orbit ships. */
    public function version(Node $node): string;

    public function storeVersion(Node $node, string $version): void;

    public function purge(Node $node): void;
}
