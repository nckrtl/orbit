<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentResult
{
    private function __construct(
        public bool $succeeded,
        public ?DeploymentRelease $release,
        public ?DeploymentRelease $selectedRelease,
        public ?DeploymentFailure $failure,
        /** @var list<DeploymentCommandResult> */
        public array $commands,
    ) {}

    /** @param list<DeploymentCommandResult> $commands */
    public static function succeeded(DeploymentRelease $release, array $commands = []): self
    {
        return new self(true, $release, $release, null, $commands);
    }

    /** @param list<DeploymentCommandResult> $commands */
    public static function failed(
        ?DeploymentRelease $release,
        ?DeploymentRelease $selectedRelease,
        DeploymentFailureBoundary $boundary,
        string $errorCode,
        array $commands = [],
    ): self {
        return new self(
            false,
            $release,
            $selectedRelease,
            new DeploymentFailure($boundary, $errorCode),
            $commands,
        );
    }
}
