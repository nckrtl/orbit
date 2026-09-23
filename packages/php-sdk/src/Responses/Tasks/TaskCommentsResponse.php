<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskCommentsResponse
{
    /** @param list<TaskCommentResponse> $comments */
    public function __construct(
        public array $comments,
        public string $requestId,
    ) {}

    /** @return array{comments: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'comments' => array_map(static function (TaskCommentResponse $comment): array {
                $data = $comment->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->comments),
            'request_id' => $this->requestId,
        ];
    }
}
