<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class InstanceInspectionData
{
    /** @mago-expect lint:excessive-parameter-list The value keeps the complete bounded instance projection. */
    public function __construct(
        public bool $checkoutExists,
        public bool $documentRootExists,
        public bool $caddyProjectionMatches,
        public bool $phpFpmProjectionMatches,
        public ?bool $certificateProjectionMatches,
        public ?bool $dnsProjectionMatches,
    ) {}
}
