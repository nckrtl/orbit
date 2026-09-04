<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Domain\Routes\RoutePublication;

/** @mago-expect lint:excessive-parameter-list The value carries the complete bounded Route creation contract. */
final readonly class CreateRouteData
{
    public function __construct(
        public int $appId,
        public string $hostname,
        public RoutePublication $publication,
        public ?int $appInstanceId,
        public ?int $nodeId,
        public ?int $clusterId,
    ) {}
}
