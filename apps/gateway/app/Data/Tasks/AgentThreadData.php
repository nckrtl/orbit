<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\AgentThreadState;
use App\Models\AgentThread;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AgentThreadData extends Data
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public ?int $taskId,
        public ?int $nodeId,
        public string $role,
        public ?string $model,
        public ?string $effort,
        public string $driver,
        public string $externalId,
        public ?AgentThreadState $state,
        public ?string $observedAt,
        public ?string $observationError,
        public ?string $error,
        public ?int $tokens,
        public ?int $linesAdded,
        public ?int $linesDeleted,
    ) {}

    public static function fromModel(AgentThread $thread): self
    {
        return new self($thread->id, $thread->task_group_id, $thread->task_id, $thread->node_id, $thread->role, $thread->model, $thread->effort, $thread->driver, $thread->external_id, $thread->state, $thread->observed_at?->toIso8601String(), $thread->observation_error, $thread->error, $thread->tokens, $thread->lines_added, $thread->lines_deleted);
    }
}
