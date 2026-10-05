<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class RenameInstanceData
{
    public function __construct(public ?string $branch = null, public ?string $domain = null, public ?string $app = null) {}
}
