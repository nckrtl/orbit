<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Node;

interface AnalyticsSecretManager
{
    /** Plausible's `SECRET_KEY_BASE`: generated once, then the same on every converge, so sessions survive a replaced Process. */
    public function secretKeyBase(Node $node): string;

    public function purge(Node $node): void;
}
