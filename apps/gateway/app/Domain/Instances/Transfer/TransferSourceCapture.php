<?php

declare(strict_types=1);

namespace App\Domain\Instances\Transfer;

use App\Domain\Instances\InstanceSourceLayout;

final readonly class TransferSourceCapture
{
    /**
     * @param  list<string>  $refs
     */
    public function __construct(
        public int $instanceId,
        public int $nodeId,
        public InstanceSourceLayout $layout,
        public string $sourcePath,
        public ?string $commonRepositoryPath,
        public string $head,
        public ?string $branch,
        public bool $detached,
        public string $archiveIdentity,
        public array $refs,
    ) {}
}
