<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolInventoryReport;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Infrastructure\Tools\HomebrewInventoryInspector;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Models\Node;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Reads Homebrew and Vite+ inventory for one Node.
 * It does not materialize a manager, take a manager lock, or write Tool intent.
 */
final readonly class ScanToolInventoryAction
{
    public function __construct(
        private HomebrewInventoryInspector $homebrew,
        private VpInventoryInspector $vp,
        private ToolNodeEligibility $eligibility,
    ) {}

    public function execute(int $nodeId): ToolInventoryReport
    {
        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            throw (new ModelNotFoundException)->setModel(Node::class, [$nodeId]);
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                $node,
                'tool.node_inactive',
                'Tools can be scanned only on an active node.',
            );
        }

        if (! $this->eligibility->allows($node)) {
            throw $this->failure(
                $node,
                'tool.node_unmanaged',
                'Tools can be scanned only on a Gateway-managed node.',
            );
        }

        $managers = $this->managers($node);

        return new ToolInventoryReport(
            nodeId: $node->id,
            observedAt: Carbon::now('UTC')->format('Y-m-d\TH:i:sP'),
            managers: $managers,
        );
    }

    /**
     * @return list<ToolInventoryScan>
     */
    private function managers(Node $node): array
    {
        $byManager = [];

        foreach ([...$this->homebrew->inspect($node), $this->vp->inspect($node)] as $scan) {
            $byManager[$scan->manager->value] = $scan;
        }

        $ordered = [];

        foreach ([ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp] as $manager) {
            $scan = $byManager[$manager->value] ?? null;

            if (! $scan instanceof ToolInventoryScan || $scan->manager !== $manager) {
                throw new LogicException('The tool inventory scan did not return every manager.');
            }

            $ordered[] = $scan;
        }

        return $ordered;
    }

    private function failure(Node $node, string $errorCode, string $message): ToolOperationException
    {
        return new ToolOperationException(
            step: 'scan',
            errorCode: $errorCode,
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            nodeId: $node->id,
            manager: '',
            package: '',
            versionConstraint: null,
            message: $message,
        );
    }
}
