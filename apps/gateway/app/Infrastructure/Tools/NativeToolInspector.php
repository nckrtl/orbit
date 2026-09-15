<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspectionOutcome;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerRegistry;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Throwable;

final readonly class NativeToolInspector implements ToolInspector
{
    public function __construct(
        private ToolManagerRegistry $registry,
    ) {}

    public function inspect(Tool $tool): ToolInspectionData
    {
        return $this->inspectMany([$tool])[0]->data();
    }

    /**
     * @param  list<Tool>  $tools
     * @return list<ToolInspectionOutcome>
     */
    public function inspectMany(array $tools): array
    {
        /** @var array<int, ToolInspectionOutcome> $outcomes */
        $outcomes = [];
        /** @var array<string, array{node: Node, manager: ToolManager, indexes: list<int>, tools: list<Tool>}> $groups */
        $groups = [];

        foreach ($tools as $index => $tool) {
            try {
                [$node, $manager] = $this->resolvedManager($tool);
            } catch (ToolInspectionException) {
                $outcomes[$index] = ToolInspectionOutcome::failed();

                continue;
            }

            $key = $node->id.'|'.$manager->name()->value;
            $groups[$key]['node'] = $node;
            $groups[$key]['manager'] = $manager;
            $groups[$key]['indexes'][] = $index;
            $groups[$key]['tools'][] = $tool;
        }

        foreach ($groups as $group) {
            $manager = $group['manager'];

            if ($manager instanceof ComposerToolManager) {
                $this->inspectComposerScope($manager, $group['node'], $group['indexes'], $group['tools'], $outcomes);

                continue;
            }

            foreach ($group['indexes'] as $offset => $index) {
                $outcomes[$index] = $this->inspectManagedTool(
                    $manager,
                    $group['node'],
                    $group['tools'][$offset],
                );
            }
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * @return array{Node, ToolManager}
     */
    private function resolvedManager(Tool $tool): array
    {
        /** @var mixed $node */
        $node = $tool->getRelationValue('node');
        /** @var mixed $managerRecord */
        $managerRecord = $tool->getRelationValue('manager');

        if (
            ! $node instanceof Node
            || ! $managerRecord instanceof ToolManagerRecord
            || $managerRecord->node_id !== $node->id
        ) {
            throw new ToolInspectionException;
        }

        $manager = $this->registry->find($managerRecord->name);
        if ($manager === null || ! $manager->supportsNode($node)) {
            throw new ToolInspectionException;
        }

        return [$node, $manager];
    }

    /**
     * @param  list<int>  $indexes
     * @param  list<Tool>  $tools
     * @param  array<int, ToolInspectionOutcome>  $outcomes
     */
    private function inspectComposerScope(
        ComposerToolManager $manager,
        Node $node,
        array $indexes,
        array $tools,
        array &$outcomes,
    ): void {
        try {
            $inventory = $manager->installedInventory($node);
        } catch (Throwable) {
            foreach ($indexes as $index) {
                $outcomes[$index] = ToolInspectionOutcome::failed();
            }

            return;
        }

        foreach ($indexes as $offset => $index) {
            $tool = $tools[$offset];

            if (! $manager->validatePackage($tool->package)) {
                $outcomes[$index] = ToolInspectionOutcome::failed();

                continue;
            }

            $outcomes[$index] = $this->outcomeFromVersion(
                $manager,
                fn (): ?string => $inventory->versionFor($tool->package),
            );
        }
    }

    private function inspectManagedTool(ToolManager $manager, Node $node, Tool $tool): ToolInspectionOutcome
    {
        return $this->outcomeFromVersion(
            $manager,
            fn (): ?string => $manager->installedVersion($node, $tool->package),
        );
    }

    /** @param callable(): ?string $readVersion */
    private function outcomeFromVersion(ToolManager $manager, callable $readVersion): ToolInspectionOutcome
    {
        try {
            $rawVersion = $readVersion();
            if ($rawVersion === null) {
                return ToolInspectionOutcome::verified(new ToolInspectionData(false, null));
            }

            return ToolInspectionOutcome::verified(new ToolInspectionData(
                true,
                $manager->normalizeVersion($rawVersion),
            ));
        } catch (ToolInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            return ToolInspectionOutcome::failed();
        }
    }
}
