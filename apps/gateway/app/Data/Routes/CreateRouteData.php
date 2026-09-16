<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Domain\Routes\RoutePublication;

final readonly class CreateRouteData
{
    public function __construct(
        public string $domain,
        public RoutePublication $publication,
        public ?int $appId = null,
        public ?int $appInstanceId = null,
        public ?int $nodeId = null,
        public ?int $clusterId = null,
        public ?string $upstream = null,
        public ?int $processId = null,
    ) {}

    public function isCustomProxy(): bool
    {
        return $this->upstream !== null || $this->processId !== null;
    }
}
