<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Herdr;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

final readonly class HerdrSessionResponse
{
    /**
     * @param  array{process: string, listener: string, session: string}  $health
     */
    public function __construct(
        public int $id,
        public string $node,
        public int $nodeId,
        public string $session,
        public string $user,
        public ?int $processId,
        public ?string $observerUrl,
        public string $status,
        public ?string $herdrVersion,
        public ?int $protocol,
        public array $health,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            node: is_string($data['node'] ?? null) ? $data['node'] : '',
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            session: is_string($data['session'] ?? null) ? $data['session'] : '',
            user: is_string($data['user'] ?? null) ? $data['user'] : '',
            processId: is_int($data['process_id'] ?? null) ? $data['process_id'] : null,
            observerUrl: is_string($data['observer_url'] ?? null) ? $data['observer_url'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            herdrVersion: is_string($data['herdr_version'] ?? null) ? $data['herdr_version'] : null,
            protocol: is_int($data['protocol'] ?? null) ? $data['protocol'] : null,
            health: self::health($data['health'] ?? null),
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            requestId: $requestId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node' => $this->node,
            'node_id' => $this->nodeId,
            'session' => $this->session,
            'user' => $this->user,
            'process_id' => $this->processId,
            'observer_url' => $this->observerUrl,
            'status' => $this->status,
            'herdr_version' => $this->herdrVersion,
            'protocol' => $this->protocol,
            'health' => $this->health,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @return array{process: string, listener: string, session: string}
     */
    private static function health(mixed $value): array
    {
        $health = is_array($value) ? $value : [];

        return [
            'process' => is_string($health['process'] ?? null) ? $health['process'] : '',
            'listener' => is_string($health['listener'] ?? null) ? $health['listener'] : '',
            'session' => is_string($health['session'] ?? null) ? $health['session'] : '',
        ];
    }
}
