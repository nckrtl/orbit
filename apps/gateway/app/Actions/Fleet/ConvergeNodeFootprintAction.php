<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\NodeFootprintData;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;

/**
 * `orbit node:converge`: re-applies the Node's Gateway-rendered footprint under its role lock, the step
 * the fleet rollout runs on each Node. It never changes an Instance or a role and never runs `node:add`.
 */
final readonly class ConvergeNodeFootprintAction
{
    public function __construct(
        private NodeFootprint $footprint,
        private ManagedNodeEligibility $eligibility,
        private NodeRoleConvergeLock $lock,
        private NodeUpdateLock $updateLock,
    ) {}

    public function execute(Node $node, bool $force = false): NodeFootprintData
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node has a Gateway-rendered footprint.', 422);
        }

        try {
            $result = $this->lock->run($node, fn () => $this->updateLock->run($node, fn () => $this->footprint->converge($node, $force)), errorCode: 'node.converge_failed', step: 'node-lock');
        } catch (NodeRoleOperationException $exception) {
            throw new ResourceOperationException($exception->underlyingErrorCode, $exception->getMessage(), 409, $exception);
        }

        return new NodeFootprintData($node->id, $node->name, $result->artifacts, $result->digest, $result->changed());
    }
}
