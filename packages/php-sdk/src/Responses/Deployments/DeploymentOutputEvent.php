<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class DeploymentOutputEvent extends DeploymentEvent implements JsonSerializable
{
    public function __construct(
        int $sequence,
        string $requestId,
        public string $stream,
        #[SensitiveParameter]
        public string $data,
    ) {
        parent::__construct($sequence, $requestId);
    }

    /** @return array{sequence: int, request_id: string, stream: string, data: string} */
    public function __debugInfo(): array
    {
        return [
            'sequence' => $this->sequence,
            'request_id' => $this->requestId,
            'stream' => $this->stream,
            'data' => '[OUTPUT]',
        ];
    }

    /** @return array{type: class-string<self>, sequence: int, request_id: string, stream: string, data: string} */
    public function jsonSerialize(): array
    {
        return ['type' => self::class, ...$this->__debugInfo()];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit deployment output events cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit deployment output events cannot be unserialized.');
    }
}
