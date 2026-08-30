<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class MetricsMutationResponse
{
    public function __construct(
        public int $nodeId,
        public string $status,
        public string $requestId,
        public ?string $publication = null,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $publication = $data['publication'] ?? null;

        if (
            ! is_int($data['node_id'] ?? null)
            || $data['node_id'] < 1
            || ! is_string($data['status'] ?? null)
            || ! in_array($data['status'], ['active', 'removed', 'enabled', 'disabled'], strict: true)
            || ! self::isPublication($publication)
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid metrics mutation data.',
                requestId: $requestId,
            );
        }

        return new self(
            $data['node_id'],
            $data['status'],
            $requestId,
            is_string($publication) ? $publication : null,
        );
    }

    /**
     * Only a disable carries a publication outcome, so the key is absent
     * rather than null on every other mutation.
     *
     * @return array{node_id:int,status:string,request_id:string,publication?:string}
     */
    public function toArray(): array
    {
        $data = [
            'node_id' => $this->nodeId,
            'status' => $this->status,
            'request_id' => $this->requestId,
        ];

        if ($this->publication === null) {
            return $data;
        }

        return [...$data, 'publication' => $this->publication];
    }

    /** A publication outcome is absent, or one of the two known strings. */
    private static function isPublication(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && in_array($value, ['cleaned', 'uncleaned'], strict: true);
    }
}
