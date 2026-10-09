<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

final readonly class DeploymentResult
{
    private function __construct(
        public bool $succeeded,
        public ?DeploymentRelease $release,
        public ?DeploymentRelease $selectedRelease,
        public ?DeploymentFailure $failure,
        /** @var list<DeploymentCommandResult> */
        public array $commands,
        public ?string $commit,
    ) {}

    /** @param list<DeploymentCommandResult> $commands */
    public static function succeeded(DeploymentRelease $release, array $commands = []): self
    {
        return new self(true, $release, $release, null, $commands, $release->commit);
    }

    /**
     * A development default deployed in its checkout. It has no release.
     *
     * @param  list<DeploymentCommandResult>  $commands
     */
    public static function checkedOut(string $commit, array $commands = []): self
    {
        return new self(true, null, null, null, $commands, $commit);
    }

    /** @param list<DeploymentCommandResult> $commands */
    public static function failed(
        ?DeploymentRelease $release,
        ?DeploymentRelease $selectedRelease,
        DeploymentFailureBoundary $boundary,
        string $errorCode,
        array $commands = [],
        ?string $commit = null,
    ): self {
        return new self(
            false,
            $release,
            $selectedRelease,
            new DeploymentFailure($boundary, $errorCode),
            $commands,
            $commit ?? $release?->commit,
        );
    }
}
