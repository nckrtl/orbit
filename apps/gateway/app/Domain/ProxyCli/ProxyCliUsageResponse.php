<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliUsageResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public mixed $body,
        public array $headers = [],
        public ?int $retryAfterSeconds = null,
    ) {}
}
