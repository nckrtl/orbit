<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\InstanceRemovalMember;

interface DevelopmentInstanceSourceFinalizer
{
    public function prepare(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): void;

    public function revalidate(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceRevalidationState;

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory;

    public function finalize(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): string;
}
