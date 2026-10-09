<?php

declare(strict_types=1);

namespace App\Domain\Compute;

enum SandboxImageStatus: string
{
    case Building = 'building';
    /** The build failed; its VMs and unpublished template are being deleted. */
    case Failing = 'failing';
    case Failed = 'failed';
    case Published = 'published';
    /** Retention deleted the published template. */
    case Retired = 'retired';
}
