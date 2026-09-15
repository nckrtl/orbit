<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\AppInstances\AppInstanceSourceLayout;

final readonly class TransferCheckout
{
    public function __construct(
        public int $nodeId,
        public string $path,
        public AppInstanceSourceLayout $layout,
        public string $head,
        public ?string $branch,
        public bool $detached,
    ) {}
}
