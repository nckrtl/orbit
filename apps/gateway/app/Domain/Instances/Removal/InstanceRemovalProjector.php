<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\InstanceRemovalMember;

interface InstanceRemovalProjector
{
    public function clearRouteTarget(InstanceRemovalMember $member): string;

    /**
     * Withdraws the development member's PHP-FPM pool and reloads PHP-FPM before its source is deleted,
     * so the live pool file never names a missing working directory.
     */
    public function withdrawPhpPool(InstanceRemovalMember $member): void;

    public function cleanupRuntime(InstanceRemovalMember $member): void;
}
