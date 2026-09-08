<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstanceRemovalMember;

interface DevelopmentAppInstanceSourceFinalizer
{
    public function prepare(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): void;

    public function revalidate(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceRevalidationState;

    public function inspectRecorded(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceInventory;

    public function finalize(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): string;
}
