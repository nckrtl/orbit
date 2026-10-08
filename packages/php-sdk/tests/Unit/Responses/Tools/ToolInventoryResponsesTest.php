<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Tools\ToolInventoryManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolInventoryPackageResponse;
use Orbit\Sdk\Responses\Tools\ToolInventoryResponse;

describe('tool inventory responses', function (): void {
    it('preserves recorded formula, cask, dependency, and unsupported package facts', function (): void {
        $inventory = tool_inventory_from_fixture('discovered');
        $packages = tool_inventory_packages($inventory);

        expect($inventory->nodeId)
            ->toBe(2)
            ->and($inventory->observedAt)
            ->toBe('2026-04-26T12:00:00+00:00')
            ->and(array_map(static fn (ToolInventoryManagerResponse $manager): string => $manager->manager, $inventory->managers))
            ->toBe(['brew', 'brew-cask', 'vp'])
            ->and($packages['brew']['openssl@3']->packageKind)
            ->toBe('formula')
            ->and($packages['brew']['openssl@3']->dependency)
            ->toBeTrue()
            ->and($packages['brew']['openssl@3']->adoption)
            ->toBe('unsupported')
            ->and($packages['brew']['openssl@3']->adoptionBlock)
            ->toBe('dependency')
            ->and($packages['brew']['ripgrep']->registered)
            ->toBeTrue()
            ->and($packages['brew']['ripgrep']->toolId)
            ->toBe(2)
            ->and($packages['brew']['ripgrep']->adoption)
            ->toBe('supported')
            ->and($packages['brew']['wireguard-tools']->adoptionBlock)
            ->toBe('protected')
            ->and($packages['brew-cask']['docker']->packageKind)
            ->toBe('cask')
            ->and($packages['brew-cask']['docker']->adoptionBlock)
            ->toBe('authorization_required')
            ->and($packages['brew-cask']['font-hack']->adoption)
            ->toBe('supported')
            ->and($packages['vp']['@openai/codex']->packageKind)
            ->toBe('global')
            ->and($packages['vp']['@openai/codex']->toolId)
            ->toBe(1)
            ->and($packages['vp']['pnpm']->adoptionBlock)
            ->toBe('protected')
            ->and($packages['vp']['typescript']->installedVersion)
            ->toBeNull()
            ->and($packages['vp']['typescript']->adoptionBlock)
            ->toBe('version_unreadable');
    });

    it('keeps an empty completed inventory distinct from a partial scan', function (string $fixture, array $states): void {
        $inventory = tool_inventory_from_fixture($fixture);

        expect(array_map(
            static fn (ToolInventoryManagerResponse $manager): array => [
                $manager->scanState,
                array_map(
                    static fn (ToolInventoryPackageResponse $package): string => $package->package,
                    $manager->packages,
                ),
            ],
            $inventory->managers,
        ))->toBe($states)
            ->and($inventory->toArray())
            ->toBe([...tool_inventory_fixture($fixture)['data'], 'request_id' => tool_inventory_request_id()]);
    })->with([
        'empty' => ['empty', [
            ['complete', []],
            ['complete', []],
            ['complete', []],
        ]],
        'linux' => ['linux', [
            ['complete', ['ripgrep']],
            ['unsupported', []],
            ['absent', []],
        ]],
        'partial' => ['partial', [
            ['complete', ['ripgrep']],
            ['incomplete', []],
            ['conflicting', []],
        ]],
    ]);

    it('round-trips the discovered inventory without nested request ids', function (string $fixture): void {
        $body = tool_inventory_fixture($fixture);
        $inventory = ToolInventoryResponse::fromGatewayData($body['data'], $body['meta']['request_id']);
        $encoded = $inventory->toArray();

        expect($encoded)->toBe([...$body['data'], 'request_id' => $body['meta']['request_id']]);

        foreach ($encoded['managers'] as $manager) {
            expect($manager)->not->toHaveKey('request_id');
            foreach ($manager['packages'] as $package) {
                expect($package)->not->toHaveKey('request_id');
            }
        }
    })->with([
        'discovered' => ['discovered'],
    ]);

    it('redacts credential-shaped package text and preserves an explicit empty version', function (): void {
        $package = new ToolInventoryPackageResponse(
            'brew',
            'https://user:inventory-secret@example.test/ripgrep',
            'formula',
            '',
            false,
            false,
            null,
            'unsupported',
            'unsupported_source',
            tool_inventory_request_id(),
        );

        expect($package->package)
            ->toBe('https://[REDACTED]@example.test/ripgrep')
            ->and($package->installedVersion)
            ->toBe('')
            ->and($package->toArray())
            ->not->toContain('inventory-secret');
    });

    it('rejects inventory values outside the transport bounds', function (string $target, array $changes): void {
        $decode = static function () use ($target, $changes): void {
            $package = tool_inventory_package_data($changes);
            if ($target === 'package') {
                ToolInventoryPackageResponse::fromGatewayData($package, tool_inventory_request_id());

                return;
            }

            $manager = [
                'manager' => 'brew',
                'scan_state' => 'complete',
                'packages' => [$package],
            ];
            if ($target === 'manager') {
                $manager = array_replace($manager, $changes);
                ToolInventoryManagerResponse::fromGatewayData($manager, tool_inventory_request_id());

                return;
            }

            ToolInventoryResponse::fromGatewayData([
                'node_id' => 2,
                'observed_at' => '2026-04-26T12:00:00+00:00',
                'managers' => [$manager],
                ...($target === 'inventory' ? $changes : []),
            ], tool_inventory_request_id());
        };

        expect($decode)->toThrow(InvalidArgumentException::class);
    })->with([
        'zero tool id' => ['package', ['tool_id' => 0]],
        'string dependency' => ['package', ['dependency' => 'true']],
        'empty package' => ['package', ['package' => '']],
        'oversized package' => ['package', ['package' => str_repeat('p', 256)]],
        'unsafe package kind' => ['package', ['package_kind' => "formula\nunsafe"]],
        'missing adoption' => ['package', ['adoption' => null]],
        'non-list packages' => ['manager', ['packages' => ['package' => 'ripgrep']]],
        'unsafe scan state' => ['manager', ['scan_state' => 'Complete']],
        'zero node' => ['inventory', ['node_id' => 0]],
        'empty observed time' => ['inventory', ['observed_at' => '']],
        'string node' => ['inventory', ['node_id' => '2']],
    ]);
});

function tool_inventory_from_fixture(string $name): ToolInventoryResponse
{
    $body = tool_inventory_fixture($name);

    return ToolInventoryResponse::fromGatewayData($body['data'], $body['meta']['request_id']);
}

/** @return array{data: array<string, mixed>, meta: array{request_id: string}} */
function tool_inventory_fixture(string $name): array
{
    $path = dirname(__DIR__, 4)."/fixtures/tools/tool-scan/{$name}.json";
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($fixture) || ! is_array($fixture['body'] ?? null)) {
        throw new RuntimeException("Tool inventory fixture {$name} is unreadable.");
    }

    /** @var array{data: array<string, mixed>, meta: array{request_id: string}} $body */
    $body = $fixture['body'];

    return $body;
}

/**
 * @return array<string, array<string, ToolInventoryPackageResponse>>
 */
function tool_inventory_packages(ToolInventoryResponse $inventory): array
{
    $packages = [];
    foreach ($inventory->managers as $manager) {
        foreach ($manager->packages as $package) {
            $packages[$manager->manager][$package->package] = $package;
        }
    }

    return $packages;
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tool_inventory_package_data(array $overrides = []): array
{
    return array_replace([
        'manager' => 'brew',
        'package' => 'ripgrep',
        'package_kind' => 'formula',
        'installed_version' => '14.1.1',
        'dependency' => false,
        'registered' => false,
        'tool_id' => null,
        'adoption' => 'supported',
        'adoption_block' => null,
    ], $overrides);
}

function tool_inventory_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
