<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspector;
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
        try {
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

            $manager = $this->registry->find($managerRecord->name->value);
            if ($manager === null || ! $manager->supportsNode($node)) {
                throw new ToolInspectionException;
            }

            $rawVersion = $manager->installedVersion($node, $tool->package);
            if ($rawVersion === null) {
                return new ToolInspectionData(false, null);
            }

            $normalizedVersion = $manager->normalizeVersion($rawVersion);
            if ($normalizedVersion === null) {
                throw new ToolInspectionException;
            }

            return new ToolInspectionData(true, $normalizedVersion);
        } catch (ToolInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ToolInspectionException;
        }
    }
}
