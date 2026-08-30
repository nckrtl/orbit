<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Metrics;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class MetricsCredentialsResponse
{
    public function __construct(
        public string $url,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $requestId,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway credential values remain mixed until validated.
     * @param array<string,mixed> $data
     */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $url = $data['url'] ?? null;
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        if (
            ! is_string($url)
            || trim($url) === ''
            || strlen($url) > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($username)
            || trim($username) === ''
            || strlen($username) > 255
            || ! is_string($password)
            || trim($password) === ''
            || strlen($password) > 4096
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid metrics credentials.',
                requestId: $requestId,
            );
        }

        return new self($url, $username, $password, $requestId);
    }

    /** @return array{url:string,username:string,password:string,request_id:string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'username' => $this->username,
            'password' => $this->password,
            'request_id' => $this->requestId,
        ];
    }

    /** @return array{type:class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Metrics credentials cannot be serialized.');
    }
}
