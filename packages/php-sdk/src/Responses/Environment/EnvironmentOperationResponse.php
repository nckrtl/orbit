<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Environment;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class EnvironmentOperationResponse
{
    private const int MAX_KEY_COUNT = 1_024;

    private function __construct(
        public int $appInstanceId,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        string $expectedOperation,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $validRequestId = GatewayRequestId::fromTransport($requestId);

        if (
            ! self::hasExactFields($data)
            || ! is_int($data['app_instance_id'])
            || $data['app_instance_id'] < 1
            || ! is_string($data['operation'])
            || ! in_array($expectedOperation, ['import', 'update', 'sync'], strict: true)
            || $data['operation'] !== $expectedOperation
            || ! is_bool($data['changed'])
            || ! is_int($data['key_count'])
            || $data['key_count'] < 0
            || $data['key_count'] > self::MAX_KEY_COUNT
            || $validRequestId === null
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid environment operation data.',
                requestId: $validRequestId,
            );
        }

        return new self(
            appInstanceId: $data['app_instance_id'],
            operation: $data['operation'],
            changed: $data['changed'],
            keyCount: $data['key_count'],
            requestId: $validRequestId,
        );
    }

    /** @return array{app_instance_id: int, operation: string, changed: bool, key_count: int, request_id: string} */
    public function toArray(): array
    {
        return [
            'app_instance_id' => $this->appInstanceId,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function hasExactFields(#[SensitiveParameter] array $data): bool
    {
        $expected = ['app_instance_id', 'operation', 'changed', 'key_count'];

        if (count($data) !== count($expected)) {
            return false;
        }

        return array_all(
            array_keys($data),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }
}
