<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

final readonly class RootCaCertificateResponse
{
    public function __construct(
        public string $certificate,
        public string $sha256,
        public string $requestId,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'root_ca' => $this->certificate,
            'sha256' => $this->sha256,
            'request_id' => $this->requestId,
        ];
    }
}
