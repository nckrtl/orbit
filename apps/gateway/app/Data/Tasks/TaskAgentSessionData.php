<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Models\TaskAgentSession;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskAgentSessionData extends Data
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public ?int $taskId,
        public ?int $nodeId,
        public string $role,
        public ?string $model,
        public ?string $effort,
        public string $threadId,
    ) {}

    public static function fromModel(TaskAgentSession $session): self
    {
        return new self($session->id, $session->task_group_id, $session->task_id, $session->node_id, $session->role, $session->model, $session->effort, $session->thread_id);
    }
}
