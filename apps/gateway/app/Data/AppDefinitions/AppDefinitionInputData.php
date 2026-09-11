<?php

declare(strict_types=1);

namespace App\Data\AppDefinitions;

use SensitiveParameter;

final readonly class AppDefinitionInputData
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
    ) {}
}
