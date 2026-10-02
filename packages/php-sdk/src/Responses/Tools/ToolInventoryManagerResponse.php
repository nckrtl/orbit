<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ToolInventoryManagerResponse
{
    public string $manager;

    public string $scanState;

    /** @var list<ToolInventoryPackageResponse> */
    public array $packages;

    public string $requestId;

    private const int TOKEN_MAX_LENGTH = 32;

    private const string TOKEN_PATTERN = '/\A[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*\z/D';

    /**
     * @param  array<array-key, mixed>  $packages
     */
    public function __construct(
        #[SensitiveParameter]
        string $manager,
        #[SensitiveParameter]
        string $scanState,
        #[SensitiveParameter]
        array $packages,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($packages)) {
            throw new InvalidArgumentException('Invalid Tool inventory response collection.');
        }

        foreach ($packages as $package) {
            if (! $package instanceof ToolInventoryPackageResponse) {
                throw new InvalidArgumentException('Invalid Tool inventory response collection member.');
            }
        }

        $this->manager = self::directToken($manager, 'manager');
        $this->scanState = self::directToken($scanState, 'scan_state');
        $this->packages = $packages;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $packages = $data['packages'] ?? null;
        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new InvalidArgumentException('Invalid Tool inventory response field [packages].');
        }

        $decoded = [];
        foreach ($packages as $package) {
            if (
                ! is_array($package)
                || ! array_all(array_keys($package), static fn (int|string $key): bool => is_string($key))
            ) {
                throw new InvalidArgumentException('Invalid Tool inventory response field [packages].');
            }

            $decoded[] = ToolInventoryPackageResponse::fromGatewayData(self::stringMap($package), $requestId);
        }

        return new self(
            manager: self::requiredString($data, 'manager'),
            scanState: self::requiredString($data, 'scan_state'),
            packages: $decoded,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     manager: string,
     *     scan_state: string,
     *     packages: list<array<string, bool|int|string|null>>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'manager' => $this->manager,
            'scan_state' => $this->scanState,
            'packages' => array_map(
                static function (ToolInventoryPackageResponse $package): array {
                    $data = $package->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->packages,
            ),
            'request_id' => $this->requestId,
        ];
    }

    private static function directToken(#[SensitiveParameter] string $value, string $key): string
    {
        if ($value === '' || strlen($value) > self::TOKEN_MAX_LENGTH || preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
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
                throw new InvalidArgumentException('Invalid Tool inventory response field [packages].');
            }

            $map[$key] = $item;
        }

        return $map;
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
