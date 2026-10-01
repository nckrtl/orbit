<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ToolInventoryResponse
{
    public int $nodeId;

    public string $observedAt;

    /** @var list<ToolInventoryManagerResponse> */
    public array $managers;

    public string $requestId;

    private const int TEXT_MAX_LENGTH = 255;

    /**
     * @param  array<array-key, mixed>  $managers
     */
    public function __construct(
        int $nodeId,
        #[SensitiveParameter]
        string $observedAt,
        #[SensitiveParameter]
        array $managers,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if ($nodeId < 1) {
            throw new InvalidArgumentException('Invalid Tool inventory response identifier.');
        }

        if (! array_is_list($managers)) {
            throw new InvalidArgumentException('Invalid Tool inventory response collection.');
        }

        foreach ($managers as $manager) {
            if (! $manager instanceof ToolInventoryManagerResponse) {
                throw new InvalidArgumentException('Invalid Tool inventory response collection member.');
            }
        }

        if ($observedAt === '' || strlen($observedAt) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid Tool inventory response field [observed_at].');
        }

        $this->nodeId = $nodeId;
        $this->observedAt = new CredentialRedactor()->redactText($observedAt);
        $this->managers = $managers;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $managers = $data['managers'] ?? null;
        if (! is_array($managers) || ! array_is_list($managers)) {
            throw new InvalidArgumentException('Invalid Tool inventory response field [managers].');
        }

        $decoded = [];
        foreach ($managers as $manager) {
            if (
                ! is_array($manager)
                || ! array_all(array_keys($manager), static fn (int|string $key): bool => is_string($key))
            ) {
                throw new InvalidArgumentException('Invalid Tool inventory response field [managers].');
            }

            $decoded[] = ToolInventoryManagerResponse::fromGatewayData(self::stringMap($manager), $requestId);
        }

        return new self(
            nodeId: self::requiredInteger($data, 'node_id'),
            observedAt: self::requiredString($data, 'observed_at'),
            managers: $decoded,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     node_id: int,
     *     observed_at: string,
     *     managers: list<array<string, mixed>>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'observed_at' => $this->observedAt,
            'managers' => array_map(
                static function (ToolInventoryManagerResponse $manager): array {
                    $data = $manager->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->managers,
            ),
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringMap(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Tool inventory response field [managers].');
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /** @param array<string, mixed> $data */
    private static function requiredInteger(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return $data[$key];
    }
}
