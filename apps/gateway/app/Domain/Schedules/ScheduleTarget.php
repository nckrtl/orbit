<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\AppInstance;
use App\Models\Node;

final readonly class ScheduleTarget
{
    public function __construct(
        public Node $node,
        public string $user,
        public string $group,
        public string $home,
        public string $workingDirectory,
        public string $shell,
        public bool $loginShell,
        public ?AppInstance $appInstance,
    ) {}

    public function isProduction(): bool
    {
        return $this->appInstance?->environment === 'production';
    }
}
