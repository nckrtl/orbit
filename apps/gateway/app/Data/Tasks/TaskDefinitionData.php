<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskDefinitionStatus;
use App\Models\TaskDefinition;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use stdClass;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskDefinitionData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>|null  $schedule
     * @param  list<array<string, mixed>>  $phases
     * @param  list<array<string, mixed>>  $subtasks
     */
    public function __construct(
        public int $projectId,
        public string $name,
        public string $title,
        public string $brief,
        public array $parameters,
        public TaskDefinitionStatus $status,
        public ?array $schedule,
        public array $phases,
        public array $subtasks,
    ) {}

    public static function fromModel(TaskDefinition $definition): self
    {
        return new self(
            projectId: $definition->project_id,
            name: $definition->name,
            title: $definition->title,
            brief: $definition->brief,
            parameters: $definition->parameters,
            status: $definition->status,
            schedule: self::schedule($definition->schedule),
            phases: $definition->phases,
            subtasks: self::subtasks($definition->subtasks),
        );
    }

    /**
     * Schedule values are an object. PHP stores an empty object as an empty list, so the response restores it.
     *
     * @param  array<string, mixed>|null  $schedule
     * @return array<string, mixed>|null
     */
    private static function schedule(?array $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        if (array_key_exists('values', $schedule) && $schedule['values'] === []) {
            $schedule['values'] = new stdClass;
        }

        return $schedule;
    }

    /**
     * Arguments are an object. PHP stores an empty object as an empty list, so the response restores it.
     *
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<array<string, mixed>>
     */
    private static function subtasks(array $subtasks): array
    {
        $normalized = [];

        foreach ($subtasks as $subtask) {
            if (array_key_exists('arguments', $subtask) && $subtask['arguments'] === []) {
                $subtask['arguments'] = new stdClass;
            }

            $normalized[] = $subtask;
        }

        return $normalized;
    }
}
