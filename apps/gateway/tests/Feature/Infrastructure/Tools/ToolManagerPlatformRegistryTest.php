<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerRegistry;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\ToolManagerFakeSshExecutor;

it('offers brew, brew-cask, and vp on macOS without materializing them and keeps the Linux set', function (): void {
    $ssh = new ToolManagerFakeSshExecutor([]);
    app()->instance(SshExecutor::class, $ssh);
    $registry = app(ToolManagerRegistry::class);
    $names = static fn (Node $node): array => array_map(
        static fn (ToolManager $manager): string => $manager->name()->value,
        $registry->supportedFor($node),
    );

    expect($names(tool_platform_node('linux')))
        ->toBe(['apt', 'vp', 'composer', 'brew'])
        ->and($names(tool_platform_node('macos')))
        ->toBe(['vp', 'brew', 'brew-cask'])
        ->and($ssh->arguments())
        ->toBeEmpty();
});

function tool_platform_node(string $platform): Node
{
    return new Node([
        'name' => 'tool-platform-node',
        'status' => 'active',
        'platform' => $platform,
        'architecture' => $platform === 'macos' ? 'arm64' : 'x86_64',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.40',
    ]);
}
