<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

/** @mago-expect lint:excessive-parameter-list The request carries six independent creation inputs. */
final readonly class CreateAppInstanceData
{
    public function __construct(
        public int $appId,
        public int $nodeId,
        public string $name,
        public ?string $root,
        public ?string $hostname,
        public ?string $branch,
    ) {}
}
