<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskQuestionsResponse
{
    /** @param list<TaskQuestionResponse> $questions */
    public function __construct(
        public array $questions,
        public string $requestId,
    ) {}

    /** @return array{questions: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'questions' => array_map(static function (TaskQuestionResponse $question): array {
                $data = $question->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->questions),
            'request_id' => $this->requestId,
        ];
    }
}
