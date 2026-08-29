<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Models\Node;
use App\Models\ToolManagerRecord;
use Closure;
use LogicException;

/** @mago-expect lint:cyclomatic-complexity The materializer coordinates canonical multi-manager locking and failure recovery. */
final readonly class NativeToolManagerMaterializer implements ToolManagerMaterializer
{
    public function __construct(
        private ToolManagerRegistry $registry,
        private ToolManagerScopeLock $managerScope,
    ) {}

    public function converge(Node $node, ToolManagerName ...$managerNames): void
    {
        $this->convergeInternal($node, null, ...$managerNames);
    }

    /** @param Closure(NodeProvisioningException): void $onFailure */
    public function convergeWithFailureHandler(Node $node, Closure $onFailure, ToolManagerName ...$managerNames): void
    {
        $this->convergeInternal($node, $onFailure, ...$managerNames);
    }

    /** @param Closure(NodeProvisioningException): void|null $onFailure */
    private function convergeInternal(Node $node, ?Closure $onFailure, ToolManagerName ...$managerNames): void
    {
        $node->load('roles');

        $managers = $managerNames === []
            ? $this->registry->supportedFor($node)
            : array_map(fn (ToolManagerName $name): ToolManager => $this->requestedManager(
                $node,
                $name,
            ), $managerNames);
        $managers = array_values($managers);
        $managers = $this->uniqueManagers($managers);
        $scopeNames = array_map(
            static fn (ToolManager $manager): ToolManagerName => $manager->name(),
            $managers,
        );

        if (
            in_array(ToolManagerName::Vp, $scopeNames, strict: true)
            || in_array(ToolManagerName::Composer, $scopeNames, strict: true)
        ) {
            $scopeNames[] = ToolManagerName::Vp;
            $scopeNames[] = ToolManagerName::Composer;
        }

        $uniqueScopeNames = [];

        foreach ($scopeNames as $scopeName) {
            $uniqueScopeNames[$scopeName->value] = $scopeName;
        }

        $scopeNames = array_values($uniqueScopeNames);
        $canonicalOrder = ['apt' => 0, 'vp' => 1, 'composer' => 2];
        usort(
            $scopeNames,
            static fn (ToolManagerName $left, ToolManagerName $right): int => (
                $canonicalOrder[$left->value] <=> $canonicalOrder[$right->value]
            ),
        );

        try {
            $this->withScopes(
                $node,
                $scopeNames,
                fn (): mixed => $this->materializeWave($node, $managers, $onFailure),
            );
        } catch (ToolManagerScopeLockException $exception) {
            throw new NodeProvisioningException(
                step: "tool-manager-{$exception->manager->value}",
                errorCode: 'node.tool_manager_locked',
                message: "The {$exception->manager->value} tool manager is busy on node [{$node->name}].",
                previous: $exception,
            );
        }
    }

    /**
     * @param list<ToolManager> $managers
     * @return list<ToolManager>
     */
    private function uniqueManagers(array $managers): array
    {
        $unique = [];

        foreach ($managers as $manager) {
            $unique[$manager->name()->value] ??= $manager;
        }

        return array_values($unique);
    }

    /**
     * @param list<ToolManagerName> $managerNames
     * @param callable(): mixed $callback
     */
    private function withScopes(Node $node, array $managerNames, callable $callback): mixed
    {
        $manager = array_shift($managerNames);

        if (! $manager instanceof ToolManagerName) {
            return $callback();
        }

        return $this->managerScope->run(
            $node->id,
            $manager,
            fn (): mixed => $this->withScopes($node, $managerNames, $callback),
        );
    }

    /** @param list<ToolManager> $managers */
    private function materializeWave(Node $node, array $managers, ?Closure $onFailure): void
    {
        try {
            foreach ($managers as $manager) {
                $this->materialize($node, $manager);
            }
        } catch (NodeProvisioningException $exception) {
            if ($onFailure !== null) {
                $onFailure($exception);
            }
            $this->retireUnsupportedAppManagers($node, $exception);

            throw $exception;
        }
    }

    private function retireUnsupportedAppManagers(Node $node, NodeProvisioningException $failure): void
    {
        $failedManager = match ($failure->step) {
            'tool-manager-vp' => ToolManagerName::Vp,
            'tool-manager-composer' => ToolManagerName::Composer,
            default => null,
        };

        if (! $failedManager instanceof ToolManagerName) {
            return;
        }

        $hasActiveAppRole = $node
            ->roles()
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->where('status', LifecycleStatus::Active)
            ->exists();

        if ($hasActiveAppRole) {
            return;
        }

        $node
            ->toolManagers()
            ->whereIn('name', [ToolManagerName::Vp->value, ToolManagerName::Composer->value])
            ->where('name', '!=', $failedManager->value)
            ->where('status', LifecycleStatus::Active)
            ->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => 'app-role',
                'error_code' => 'tool_manager.app_role_required',
            ]);
    }

    private function requestedManager(Node $node, ToolManagerName $name): ToolManager
    {
        $manager = $this->registry->find($name->value);

        if ($manager === null) {
            throw new LogicException("Requested tool manager [{$name->value}] is not registered.");
        }

        if (! $manager->supportsNode($node)) {
            throw new LogicException("Requested tool manager [{$name->value}] does not support node [{$node->name}].");
        }

        return $manager;
    }

    private function materialize(Node $node, ToolManager $manager): void
    {
        $name = $manager->name();
        $record = ToolManagerRecord::query()->firstOrCreate([
            'node_id' => $node->id,
            'name' => $name,
        ], ['status' => LifecycleStatus::Provisioning]);
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
                step: "tool-manager-{$name->value}",
                errorCode: 'node.tool_manager_probe_failed',
                message: "Could not determine the {$name->value} tool manager version on node [{$node->name}].",
                previous: $exception,
                result: $exception->result,
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
