<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use InvalidArgumentException;

/** Explicit filesystem contexts; resolving a candidate never requires publishing a map. */
final readonly class AppProjectionEnvironmentTarget
{
    public function __construct(
        public string $scope,
        public InstanceEnvironmentContext $old,
        public InstanceEnvironmentContext $candidate,
        public string $oldCheckout,
        public string $candidateCheckout,
        public string $releaseIdentity,
    ) {
        if (! in_array($scope, ['stable', 'release', 'checkout'], true)
            || $releaseIdentity === '' || $old->environment !== 'development' || $candidate->environment !== 'development'
            || $old->instanceId !== $candidate->instanceId || $old->projectId !== $candidate->projectId || $old->app !== $candidate->app
            || $old->nodeId !== $candidate->nodeId || $old->executionUser !== $candidate->executionUser) {
            throw new InvalidArgumentException('Environment projection requires explicit matching development app contexts.');
        }
    }

    /** @return array<string, string> */
    public function identities(): array
    {
        return ["{$this->scope}.old" => $this->old->path, "{$this->scope}.candidate" => $this->candidate->path,
            "{$this->scope}.old_checkout" => $this->oldCheckout, "{$this->scope}.candidate_checkout" => $this->candidateCheckout,
            "{$this->scope}.release" => $this->releaseIdentity];
    }
}
