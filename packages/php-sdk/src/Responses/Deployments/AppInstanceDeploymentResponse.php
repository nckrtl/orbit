<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

final readonly class AppInstanceDeploymentResponse
{
    /** @param list<AppInstanceDeploymentEvent>|null $events */
    public function __construct(
        public int $id,
        public int $appInstanceId,
        public ?string $release,
        public ?string $branch,
        public ?string $commit,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $durationSeconds,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $selectedRelease,
        public ?string $triggeredBy,
        public ?array $events,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $events = null;

        if (is_array($data['events'] ?? null)) {
            $events = [];

            foreach ($data['events'] as $event) {
                if (is_array($event)) {
                    $events[] = AppInstanceDeploymentEvent::fromArray($event);
                }
            }
        }

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            appInstanceId: is_int($data['app_instance_id'] ?? null) ? $data['app_instance_id'] : 0,
            release: is_string($data['release'] ?? null) ? $data['release'] : null,
            branch: is_string($data['branch'] ?? null) ? $data['branch'] : null,
            commit: is_string($data['commit'] ?? null) ? $data['commit'] : null,
            startedAt: is_string($data['started_at'] ?? null) ? $data['started_at'] : '',
            finishedAt: is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            durationSeconds: is_int($data['duration_seconds'] ?? null) ? $data['duration_seconds'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: is_string($data['error_code'] ?? null) ? $data['error_code'] : null,
            selectedRelease: is_string($data['selected_release'] ?? null) ? $data['selected_release'] : null,
            triggeredBy: is_string($data['triggered_by'] ?? null) ? $data['triggered_by'] : null,
            events: $events,
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'app_instance_id' => $this->appInstanceId,
            'release' => $this->release,
            'branch' => $this->branch,
            'commit' => $this->commit,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'selected_release' => $this->selectedRelease,
            'triggered_by' => $this->triggeredBy,
            'events' => $this->events === null ? null : array_map(
                static fn (AppInstanceDeploymentEvent $event): array => $event->toArray(),
                $this->events,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
