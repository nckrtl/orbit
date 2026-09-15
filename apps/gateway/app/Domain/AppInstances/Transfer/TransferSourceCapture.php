<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\AppInstances\AppInstanceSourceLayout;

final readonly class TransferSourceCapture
{
    /**
     * @param  list<string>  $refs
     */
    public function __construct(
        public int $appInstanceId,
        public int $nodeId,
        public AppInstanceSourceLayout $layout,
        public string $sourcePath,
        public ?string $commonRepositoryPath,
        public string $head,
        public ?string $branch,
        public bool $detached,
        public string $archiveIdentity,
        public array $refs,
    ) {}
}
