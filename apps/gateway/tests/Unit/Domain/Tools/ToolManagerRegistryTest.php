<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Models\Node;

describe(ToolManagerRegistry::class, function (): void {
    it('indexes the closed manager set in constructor order and filters by node support', function (): void {
        $linuxAppNode = tool_manager_registry_node('linux');
        $apt = fake_tool_manager(ToolManagerName::Apt, static fn (): bool => true);
        $vp = fake_tool_manager(ToolManagerName::Vp, static fn (Node $node): bool => $node->platform === 'linux');
        $composer = fake_tool_manager(
            ToolManagerName::Composer,
            static fn (Node $node): bool => $node->platform === 'linux',
        );
        $registry = new ToolManagerRegistry([$apt, $vp, $composer]);

        expect($registry->find('apt'))
            ->toBe($apt)
            ->and($registry->find('vp'))
            ->toBe($vp)
            ->and($registry->find('composer'))
            ->toBe($composer)
            ->and($registry->find('npm'))
            ->toBeNull()
            ->and(array_map(
                static fn (ToolManager $manager): string => $manager->name()->value,
                $registry->supportedFor($linuxAppNode),
            ))
            ->toBe(['apt', 'vp', 'composer']);
    });
});

function fake_tool_manager(ToolManagerName $name, callable $supportsNode): ToolManager
{
    $manager = Mockery::mock(ToolManager::class);
    $manager->allows('name')->andReturn($name);
    $manager->allows('supportsNode')->andReturnUsing($supportsNode);

    return $manager;
}

function tool_manager_registry_node(string $platform): Node
{
    return new Node([
        'name' => 'tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'ssh_user' => 'orbit',
    ]);
}
