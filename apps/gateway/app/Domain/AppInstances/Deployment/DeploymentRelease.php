<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentRelease
{
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
