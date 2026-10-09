<?php

declare(strict_types=1);

namespace App\Data\ProjectDefinitions;

use SensitiveParameter;

final readonly class ProjectDefinitionInputData
{
    /**
     * @param  list<string>  $environments
     * @param  array<string, mixed>  $spec
     */
    public function __construct(
        public string $name,
        public array $environments,
        #[SensitiveParameter]
        public array $spec,
        public ?string $app = null,
    ) {}
}
