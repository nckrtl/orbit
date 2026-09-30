<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

use stdClass;

final readonly class TaskDefinitionResponse
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
        public string $status,
        public ?array $schedule,
        public array $phases,
        public array $subtasks,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $schedule = $data['schedule'] ?? null;

        return new self(
            projectId: is_int($data['project_id'] ?? null) ? $data['project_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            title: is_string($data['title'] ?? null) ? $data['title'] : '',
            brief: is_string($data['brief'] ?? null) ? $data['brief'] : '',
            parameters: self::records($data['parameters'] ?? null),
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            schedule: is_array($schedule) ? self::stringKeyed($schedule) : null,
            phases: self::records($data['phases'] ?? null),
            subtasks: self::records($data['subtasks'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'name' => $this->name,
            'title' => $this->title,
            'brief' => $this->brief,
            'parameters' => $this->parameters,
            'status' => $this->status,
            'schedule' => self::schedule($this->schedule),
            'phases' => $this->phases,
            'subtasks' => self::subtasks($this->subtasks),
            'request_id' => $this->requestId,
        ];
    }

    /**
     * Schedule values are an object. An empty object arrives as an empty list.
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
     * Arguments and routes are objects. An empty object arrives as an empty list.
     *
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<array<string, mixed>>
     */
    private static function subtasks(array $subtasks): array
    {
        $normalized = [];

        foreach ($subtasks as $subtask) {
            foreach (['arguments', 'routes'] as $field) {
                if (array_key_exists($field, $subtask) && $subtask[$field] === []) {
                    $subtask[$field] = new stdClass;
                }
            }

            $normalized[] = $subtask;
        }

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private static function records(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $records[] = self::stringKeyed($item);
            }
        }

        return $records;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
