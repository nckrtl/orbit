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
        public ?DoctorInspectionScope $scope = null,
    ) {}

    public function withScope(DoctorInspectionScope $scope): self
    {
        return new self($this->node, $this->inspection, $this->inspectionFailed, $scope);
    }
}
