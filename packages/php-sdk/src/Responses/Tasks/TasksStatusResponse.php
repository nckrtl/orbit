<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TasksStatusResponse
{
    /**
     * @param  list<TaskAssistanceResponse>|null  $assistance  Null when this route does not report assistance.
     */
    public function __construct(
        public bool $enabled,
        public string $requestId,
        public ?array $assistance = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $enabled = $data['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        return new self($enabled, $requestId, array_key_exists('assistance', $data) ? self::assistance($data['assistance'], $requestId) : null);
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
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @return list<TaskAssistanceResponse>
     */
    private static function assistance(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        $groups = [];

        foreach ($value as $group) {
            if (! is_array($group)) {
                throw TaskFields::invalid('tasks extension status', $requestId);
            }

            $data = [];
            foreach ($group as $key => $item) {
                if (! is_string($key)) {
                    throw TaskFields::invalid('tasks extension status', $requestId);
                }
                $data[$key] = $item;
            }

            $groups[] = TaskAssistanceResponse::fromGatewayData($data, $requestId);
        }

        return $groups;
    }
}
