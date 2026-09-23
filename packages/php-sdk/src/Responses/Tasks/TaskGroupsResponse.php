<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskGroupsResponse
{
    /** @param list<TaskGroupResponse> $taskGroups */
    public function __construct(
        public array $taskGroups,
        public string $requestId,
    ) {}

    /** @return array{task_groups: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'task_groups' => array_map(static function (TaskGroupResponse $group): array {
                $data = $group->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->taskGroups),
            'request_id' => $this->requestId,
        ];
    }
}
