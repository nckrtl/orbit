<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Herdr;

use LogicException;
use SensitiveParameter;

final readonly class ObservationGrantResponse
{
    public function __construct(
        #[SensitiveParameter]
        public string $observerUrl,
        public string $scope,
        public string $pane,
        public string $terminal,
        public int $cols,
        public int $rows,
        public string $expiresAt,
        public string $nonce,
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
            observerUrl: is_string($data['observer_url'] ?? null) ? $data['observer_url'] : '',
            scope: is_string($data['scope'] ?? null) ? $data['scope'] : '',
            pane: is_string($data['pane'] ?? null) ? $data['pane'] : '',
            terminal: is_string($data['terminal'] ?? null) ? $data['terminal'] : '',
            cols: is_int($data['cols'] ?? null) ? $data['cols'] : 0,
            rows: is_int($data['rows'] ?? null) ? $data['rows'] : 0,
            expiresAt: is_string($data['expires_at'] ?? null) ? $data['expires_at'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     observer_url: string,
     *     scope: string,
     *     pane: string,
     *     terminal: string,
     *     cols: int,
     *     rows: int,
     *     expires_at: string,
     *     nonce: string,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'observer_url' => $this->observerUrl,
            'scope' => $this->scope,
            'pane' => $this->pane,
            'terminal' => $this->terminal,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'expires_at' => $this->expiresAt,
            'nonce' => $this->nonce,
            'request_id' => $this->requestId,
        ];
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    public function __serialize(): array
    {
        throw new LogicException('Observation grants cannot be serialized.');
    }
}
