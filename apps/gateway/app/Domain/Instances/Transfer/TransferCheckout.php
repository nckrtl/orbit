<?php

declare(strict_types=1);

namespace App\Domain\Instances\Transfer;

use App\Domain\Instances\InstanceSourceLayout;

final readonly class TransferCheckout
{
    public function __construct(
        public int $nodeId,
        public string $path,
        public InstanceSourceLayout $layout,
        public string $head,
        public ?string $branch,
        public bool $detached,
    ) {}
}
