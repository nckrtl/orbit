<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Models\TaskQuestion;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskQuestionData extends Data
{
    public function __construct(
        public int $id,
        public int $taskId,
        public int $subtaskId,
        public int $attempt,
        public QuestionAsker $askedBy,
        public string $question,
        public QuestionStatus $status,
        public ?QuestionAsker $answeredBy,
        public ?string $answer,
        public ?QuestionCause $cause,
        public string $askedAt,
        public ?string $escalatedAt,
        public ?string $answeredAt,
    ) {}

    public static function fromModel(TaskQuestion $question): self
    {
        return new self(
            id: $question->id,
            taskId: $question->task_id,
            subtaskId: $question->subtask_id,
            attempt: $question->attempt,
            askedBy: $question->asked_by,
            question: $question->question,
            status: $question->status,
            answeredBy: $question->answered_by,
            answer: $question->answer,
            cause: $question->cause,
            askedAt: $question->asked_at->toIso8601String(),
            escalatedAt: $question->escalated_at?->toIso8601String(),
            answeredAt: $question->answered_at?->toIso8601String(),
        );
    }
}
