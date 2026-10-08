<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TasksStatusResponse
{
    /**
     * @param  list<TaskAssistanceResponse>|null  $assistance  Null when this route does not report assistance.
     * @param  string|null  $lastTickAt  When the latest `tasks:tick` started its work. Null when no tick is remembered or this route does not report it.
     * @param  list<TaskMergeResponse>  $merges  Open tasks of review-and-merge Projects. Empty when this route does not report them.
     */
    public function __construct(
        public bool $enabled,
        public string $requestId,
        public ?array $assistance = null,
        public ?string $lastTickAt = null,
        public array $merges = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $enabled = $data['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        $lastTickAt = $data['last_tick_at'] ?? null;

        if ($lastTickAt !== null && ! is_string($lastTickAt)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        return new self(
            $enabled,
            $requestId,
            array_key_exists('assistance', $data) ? self::assistance($data['assistance'], $requestId) : null,
            $lastTickAt,
            array_key_exists('merges', $data) ? array_map(static fn (array $group): TaskMergeResponse => TaskMergeResponse::fromGatewayData($group, $requestId), self::rows($data['merges'], $requestId)) : [],
        );
    }

    /**
     * @return array{enabled: bool, request_id: string}|array{
     *     enabled: bool,
     *     assistance: list<array{
     *         id: int,
     *         project_id: int,
     *         project: string|null,
     *         project_code: string|null,
     *         title: string,
     *         status: string,
     *         assistance_kind: string|null,
     *         assistance_question: string|null,
     *         assistance_reason: string|null
     *     }>,
     *     last_tick_at: string|null,
     *     merges: list<array{id: int, project_id: int, project: string|null, project_code: string|null, title: string, status: string,
     *         pr_url: string|null, pr_branch: string|null, merge_status: string|null, merge_reason: string|null}>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        if ($this->assistance === null) {
            return [
                'enabled' => $this->enabled,
                'request_id' => $this->requestId,
            ];
        }

        return [
            'enabled' => $this->enabled,
            'assistance' => array_map(static fn (TaskAssistanceResponse $group): array => $group->toArray(), $this->assistance),
            'last_tick_at' => $this->lastTickAt,
            'merges' => array_map(static fn (TaskMergeResponse $group): array => $group->toArray(), $this->merges),
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @return list<TaskAssistanceResponse>
     */
    private static function assistance(mixed $value, string $requestId): array
    {
        return array_map(static fn (array $group): TaskAssistanceResponse => TaskAssistanceResponse::fromGatewayData($group, $requestId), self::rows($value, $requestId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                throw TaskFields::invalid('tasks extension status', $requestId);
            }

            $data = [];
            foreach ($row as $key => $item) {
                if (! is_string($key)) {
                    throw TaskFields::invalid('tasks extension status', $requestId);
                }
                $data[$key] = $item;
            }

            $rows[] = $data;
        }

        return $rows;
    }
}
