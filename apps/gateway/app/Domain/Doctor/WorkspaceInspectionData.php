<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class WorkspaceInspectionData
{
    public function __construct(
        public bool $checkoutExists,
        public bool $worktreeRegistered,
        public bool $branchMatches,
        public bool $documentRootExists,
        public bool $caddyProjectionMatches,
        public bool $phpFpmProjectionMatches,
        public bool $certificateProjectionMatches,
        public bool $dnsProjectionMatches,
    ) {}
}
