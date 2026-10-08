<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceTestEnvironmentPlan
{
    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $managedKeys
     */
    public function __construct(
        #[\SensitiveParameter]
        public array $values,
        public array $managedKeys,
        public string $testDatabase,
    ) {}
}
