<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use LogicException;
use SensitiveParameter;

final readonly class MetricsCredentialsData
{
    public function __construct(
        public string $url,
        public string $username,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /** @return array{url: string, username: string, password: string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    public function __serialize(): array
    {
        throw new LogicException('Metrics credentials cannot be serialized.');
    }
}
