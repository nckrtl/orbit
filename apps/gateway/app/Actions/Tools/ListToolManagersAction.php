<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\ToolManagerData;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Models\Node;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Collection;

final readonly class ListToolManagersAction
{
    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolNodeEligibility $eligibility,
    ) {}

    /** @return Collection<int, ToolManagerData> */
    public function execute(int $nodeId): Collection
    {
        $node = Node::query()->findOrFail($nodeId);
        $persisted = ToolManagerRecord::query()
            ->where('node_id', $nodeId)
            ->get()
            ->keyBy('name');

        $states = $persisted
            ->map(static fn (ToolManagerRecord $manager): ToolManagerData => ToolManagerData::fromModel($manager));

        if ($node->status === LifecycleStatus::Active && $this->eligibility->allows($node)) {
            foreach ($this->managers->supportedFor($node) as $manager) {
                $name = $manager->name()->value;
                $states->put($name, $states->get($name) ?? ToolManagerData::uninstalled($nodeId, $name));
            }
        }

        return $states->sortBy('name', SORT_STRING)->values();
    }
}
