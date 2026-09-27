<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Models\Node;
use Closure;

final class FakeNodeConverger implements NodeConverger, RecoverableNodeConverger
{
    public function converge(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint = null, bool $rolelessOperator = false): NodeObservation
    {
        return new NodeObservation('x86_64');
    }

    public function convergeRecoverably(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint, Closure $completion, bool $rolelessOperator = false): void
    {
        $completion($this->converge($node, $identity, $expectedSshHostFingerprint, $rolelessOperator));
    }
}
