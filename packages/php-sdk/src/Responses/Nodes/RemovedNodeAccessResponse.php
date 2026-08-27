<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class RemovedNodeAccessResponse
{
    public function __construct(
        public NodeAccessNodeResponse $consumerNode,
        public NodeAccessNodeResponse $servingNode,
        public bool $alreadyAbsent,
        public bool $selfLockout,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $consumerNode = NodeAccessNodeResponse::tryFromGatewayData($data['consumer_node'] ?? null);
        $servingNode = NodeAccessNodeResponse::tryFromGatewayData($data['serving_node'] ?? null);

        if ($consumerNode === null) {
            throw new GatewayApiException(
                'Gateway response contains an invalid consumer_node summary.',
                requestId: $requestId,
            );
        }

        if ($servingNode === null) {
            throw new GatewayApiException(
                'Gateway response contains an invalid serving_node summary.',
                requestId: $requestId,
            );
        }

        return new self(
            consumerNode: $consumerNode,
            servingNode: $servingNode,
            alreadyAbsent: ($data['already_absent'] ?? false) === true,
            selfLockout: ($data['self_lockout'] ?? false) === true,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     consumer_node: array{id: int, name: string},
     *     serving_node: array{id: int, name: string},
     *     already_absent: bool,
     *     self_lockout: bool,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'consumer_node' => $this->consumerNode->toArray(),
            'serving_node' => $this->servingNode->toArray(),
            'already_absent' => $this->alreadyAbsent,
            'self_lockout' => $this->selfLockout,
            'request_id' => $this->requestId,
        ];
    }
}
