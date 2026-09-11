<?php

declare(strict_types=1);

namespace App\Data\AppDefinitions;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\ProcessDefinition;
use App\Models\ScheduleDefinition;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppRuntimeDefinitionData extends Data
{
    /**
     * @param  list<string>  $environments
     * @param  array<string, mixed>  $spec
     */
    public function __construct(
        public string $id,
        public int $appId,
        public string $name,
        public array $environments,
        public array $spec,
    ) {}

    public static function fromModel(
        ProcessDefinition|ScheduleDefinition $definition,
        bool $includeCommand = true,
    ): self {
        $spec = $definition->spec;

        if (! $includeCommand) {
            unset($spec['command']);
            $spec = new CommandActivityInputSanitizer()->sanitizeProperties($spec);
        }

        return new self(
            id: $definition->id,
            appId: $definition->app_id,
            name: $definition->name,
            environments: $definition->environments,
            spec: $spec,
        );
    }
}
