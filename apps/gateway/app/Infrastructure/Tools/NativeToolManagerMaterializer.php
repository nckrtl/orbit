<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerRegistry;
use App\Models\Node;
use App\Models\ToolManagerRecord;

final readonly class NativeToolManagerMaterializer implements ToolManagerMaterializer
{
    public function __construct(
        private ToolManagerRegistry $managers,
    ) {}

    public function converge(Node $node): void
    {
        foreach ($this->managers->supportedFor($node) as $manager) {
            $record = ToolManagerRecord::query()->firstOrNew([
                'node_id' => $node->id,
                'name' => $manager->name(),
            ]);
            $record->fill([
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            try {
                $version = $manager->managerVersion($node);
            } catch (ToolManagerException $exception) {
                $record->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'manager-version',
                    'error_code' => 'node.tool_manager_probe_failed',
                ]);

                throw new NodeProvisioningException(
                    step: "tool-manager-{$manager->name()->value}",
                    errorCode: 'node.tool_manager_probe_failed',
                    message: "Could not verify a required tool manager on node [{$node->name}].",
                    previous: $exception,
                );
            }

            $record->update([
                'status' => LifecycleStatus::Active,
                'installed_version' => $version,
                'failed_step' => null,
                'error_code' => null,
            ]);
        }
    }
}
