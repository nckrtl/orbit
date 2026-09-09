<?php

declare(strict_types=1);

use App\Actions\Tools\ListToolManagersAction;
use App\Actions\Tools\ListToolsAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;

describe('closed tool registry', function (): void {
    it('registers exactly APT, Homebrew, VP, and Composer managers', function (): void {
        $registry = app(ToolManagerRegistry::class);

        expect($registry->find(ToolManagerName::Apt->value))
            ->toBeInstanceOf(AptToolManager::class)
            ->and($registry->find(ToolManagerName::Vp->value))
            ->toBeInstanceOf(VpToolManager::class)
            ->and($registry->find(ToolManagerName::Composer->value))
            ->toBeInstanceOf(ComposerToolManager::class)
            ->and($registry->find(ToolManagerName::Brew->value))
            ->toBeInstanceOf(HomebrewToolManager::class)
            ->and($registry->find('npm'))
            ->toBeNull();
    });
});

describe('tool read actions', function (): void {
    it('lists persisted and supported uninstalled managers in stable name order', function (): void {
        $node = tool_read_node('first-tools-node');
        $other = tool_read_node('other-tools-node');
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Active,
        ]);
        $apt = $node->toolManagers()->create([
            'name' => ToolManagerName::Apt,
            'status' => LifecycleStatus::Active,
        ]);
        $other
            ->toolManagers()
            ->create([
                'name' => ToolManagerName::Composer,
                'status' => LifecycleStatus::Active,
            ]);

        $managers = app(ListToolManagersAction::class)->execute($node->id);

        expect($managers->pluck('name')->all())
            ->toBe(['apt', 'brew', 'composer', 'vp'])
            ->and($managers->pluck('nodeId')->unique()->all())
            ->toBe([$node->id])
            ->and($managers->pluck('id')->all())
            ->toBe([$apt->id, null, null, $vp->id])
            ->and($managers->pluck('status')->all())
            ->toBe(['active', 'uninstalled', 'uninstalled', 'active']);
    });

    it('keeps an unknown persisted manager readable without registering it', function (): void {
        $node = tool_read_node('rollback-manager-node');
        $record = $node->toolManagers()->create([
            'name' => 'future-manager',
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'materialize',
            'error_code' => 'node.tool_manager_materialization_failed',
        ]);

        $managers = app(ListToolManagersAction::class)->execute($node->id);

        expect($managers->pluck('name')->all())
            ->toBe(['apt', 'brew', 'composer', 'future-manager', 'vp'])
            ->and($managers->firstWhere('name', 'future-manager')?->id)
            ->toBe($record->id)
            ->and(app(ToolManagerRegistry::class)->find('future-manager'))
            ->toBeNull();
    });

    it('lists only the node tools in stable identity order with managers loaded', function (): void {
        $node = tool_read_node('listed-tools-node');
        $other = tool_read_node('unlisted-tools-node');
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Apt,
            'status' => LifecycleStatus::Active,
        ]);
        $otherManager = $other
            ->toolManagers()
            ->create([
                'name' => ToolManagerName::Vp,
                'status' => LifecycleStatus::Active,
            ]);
        $first = $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
        ]);
        $second = $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'curl',
            'status' => ToolStatus::Installed,
        ]);
        $other
            ->tools()
            ->create([
                'tool_manager_id' => $otherManager->id,
                'package' => 'typescript',
                'status' => ToolStatus::Installed,
            ]);

        $tools = new ListToolsAction()->execute($node->id);

        expect($tools->pluck('id')->all())
            ->toBe([$first->id, $second->id])
            ->and($tools->pluck('node_id')->unique()->all())
            ->toBe([$node->id])
            ->and($tools->every->relationLoaded('manager'))
            ->toBeTrue();
    });
});

function tool_read_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => fake()->unique()->ipv4(),
        'wireguard_ip' => fake()->unique()->ipv4(),
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', times: 43),
    ]);
}
