<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Models\Node;
use Tests\Support\FakeToolManager;

describe(NativeToolManagerMaterializer::class, function (): void {
    it('materializes each supported manager and updates existing rows', function (): void {
        $node = materializer_node();
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);
        $apt = materializer_manager(ToolManagerName::Apt, ['apt 3.0.3', 'apt 3.0.4']);
        $vp = materializer_manager(ToolManagerName::Vp, ['vp v0.2.6', 'vp v0.2.7']);
        $composer = materializer_manager(ToolManagerName::Composer, [
            'Composer version 2.8.10',
            'Composer version 2.8.11',
        ]);
        $materializer = new NativeToolManagerMaterializer(new ToolManagerRegistry([$apt, $vp, $composer]));

        $materializer->converge($node->load('roles'));
        $materializer->converge($node->load('roles'));

        expect(
            $node
                ->toolManagers()
                ->orderBy('name')
                ->get()
                ->map
                ->only([
                    'name',
                    'status',
                    'installed_version',
                ])
                ->all(),
        )->toBe([
            ['name' => ToolManagerName::Apt, 'status' => LifecycleStatus::Active, 'installed_version' => 'apt 3.0.4'],
            [
                'name' => ToolManagerName::Composer,
                'status' => LifecycleStatus::Active,
                'installed_version' => 'Composer version 2.8.11',
            ],
            ['name' => ToolManagerName::Vp, 'status' => LifecycleStatus::Active, 'installed_version' => 'vp v0.2.7'],
        ]);
    });

    it('materializes only apt for a roleless Linux node', function (): void {
        $node = materializer_node();
        $apt = materializer_manager(ToolManagerName::Apt, ['apt 3.0.3']);
        $vp = materializer_manager(ToolManagerName::Vp, ['vp v0.2.6']);
        $vp->supports = false;
        $composer = materializer_manager(ToolManagerName::Composer, ['Composer version 2.8.10']);
        $composer->supports = false;

        new NativeToolManagerMaterializer(new ToolManagerRegistry([$apt, $vp, $composer]))->converge($node);

        expect($node->toolManagers()->sole()->name)->toBe(ToolManagerName::Apt);
    });

    it('stores a safe failed manager state and throws a provisioning failure', function (): void {
        $node = materializer_node();
        $apt = materializer_manager(ToolManagerName::Apt, [new ToolManagerException(
            step: 'manager-version',
            message: 'The manager probe failed.',
        )]);

        expect(fn () => new NativeToolManagerMaterializer(new ToolManagerRegistry([$apt]))->converge($node))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('tool-manager-apt')
                    ->and($exception->errorCode)
                    ->toBe('node.tool_manager_probe_failed');
            });

        expect($node->toolManagers()->sole())
            ->status->toBe(LifecycleStatus::Failed)
            ->failed_step->toBe('manager-version')
            ->error_code->toBe('node.tool_manager_probe_failed');
    });
});

/** @param list<string|Throwable> $versions */
function materializer_manager(ToolManagerName $name, array $versions): FakeToolManager
{
    $manager = new FakeToolManager($name);
    $manager->managerVersions = $versions;

    return $manager;
}

function materializer_node(): Node
{
    return Node::query()->create([
        'name' => 'materializer-host',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.15',
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.15',
    ]);
}
