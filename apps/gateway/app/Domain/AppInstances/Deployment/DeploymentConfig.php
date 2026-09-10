<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Domain\SourceControl\GitBranchName;
use InvalidArgumentException;

final readonly class DeploymentConfig
{
    /**
     * @param  list<DeploymentStep>  $steps
     */
    public function __construct(
        public string $branch,
        #[\SensitiveParameter]
        public array $steps,
    ) {
        GitBranchName::validate($branch);

        if (count($steps) > 32) {
            throw new InvalidArgumentException('The deployment configuration has too many steps.');
        }

        $names = array_map(static fn (DeploymentStep $step): string => $step->name, $steps);

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('The deployment step names must be unique.');
        }

        $totalTimeout = array_sum(array_map(
            static fn (DeploymentStep $step): int => $step->timeoutSeconds,
            $steps,
        ));

        if ($totalTimeout > 3_600) {
            throw new InvalidArgumentException('The deployment configuration timeout total is too large.');
        }
    }

    /** @return list<array{name: string, phase: string, command: string, timeout_seconds: int}> */
    public function normalizedSteps(): array
    {
        return array_map(
            static fn (DeploymentStep $step): array => $step->toArray(),
            $this->steps,
        );
    }
}
