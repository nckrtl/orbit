<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use SensitiveParameter;

interface MetricsCredentialRuntime
{
    public function apply(
        Node $node,
        #[SensitiveParameter]
        string $activePassword,
        #[SensitiveParameter]
        string $pendingPassword,
    ): void;

    public function verify(Node $node, #[SensitiveParameter] string $password): bool;
}
