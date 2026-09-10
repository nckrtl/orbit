<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\AppInstance;
use App\Models\Node;

final readonly class ProcessTarget
{
    public string $defaultWorkingDirectory;

    public function __construct(
        public Node $node,
        public string $user,
        public string $checkoutPath,
        public ?string $certificateScope = null,
        public ?AppInstance $appInstance = null,
        public string $environmentFile = '',
        public bool $productionReleaseLayout = false,
    ) {
        $this->defaultWorkingDirectory = $checkoutPath;
    }
}
