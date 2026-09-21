<?php

declare(strict_types=1);

namespace App\Data\ProxyCli;

use SensitiveParameter;

final readonly class EnableProxyCliData
{
    public function __construct(
        public int $nodeId,
        public string $cacheConnection,
        public string $cliproxyUrl,
        #[SensitiveParameter]
        public string $cliproxyManagementKey,
    ) {}
}
