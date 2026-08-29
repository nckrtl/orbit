<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;

final readonly class DoctorNodeContext
{
    public function __construct(
        public Node $node,
        public NodeInspectionData $inspection,
        public bool $inspectionFailed = false,
    ) {}
}
