<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DeploymentReleasesResponse
{
    /** @param list<string> $releases */
    private function __construct(
        public array $releases,
        public ?string $selectedRelease,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        $validRequestId = GatewayRequestId::fromTransport($requestId);
        $releases = $data['releases'] ?? null;
        $selected = $data['selected_release'] ?? null;

        if (
            ! self::hasExactFields($data)
            || ! is_array($releases)
            || ! array_is_list($releases)
            || ! array_all($releases, self::validRelease(...))
            || count($releases) !== count(array_unique($releases))
            || ($selected !== null && ! self::validRelease($selected))
            || ($selected !== null && ! in_array($selected, $releases, strict: true))
            || $validRequestId === null
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid retained release data.',
                requestId: $validRequestId,
            );
        }

        return new self($releases, $selected, $validRequestId);
    }

    /** @return array{releases: list<string>, selected_release: ?string, request_id: string} */
    public function toArray(): array
    {
        return [
            'releases' => $this->releases,
            'selected_release' => $this->selectedRelease,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function hasExactFields(#[SensitiveParameter] array $data): bool
    {
        $expected = ['releases', 'selected_release'];

        return count($data) === count($expected) && array_all(
            array_keys($data),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }

    private static function validRelease(mixed $release): bool
    {
        return is_string($release)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $release) === 1;
    }
}
