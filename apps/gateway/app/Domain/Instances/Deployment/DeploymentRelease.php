<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

final readonly class DeploymentRelease
{
    /**
     * The most releases an Instance release home keeps after a deployment: the selected one and the newest others.
     * A development release that another Instance leases as its seed is never removed, also beyond this count.
     */
    public const int RETAINED_PER_HOME = 3;

    public function __construct(
        public string $name,
        public string $path,
        public string $commit,
    ) {}

    public static function isValidName(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name) === 1;
    }
}
