<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewInventoryInspector;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(HomebrewInventoryInspector::class, function (): void {
    it('classifies formulae and casks with separate identities and no manager mutation', function (): void {
        $node = homebrew_inventory_node();
        $other = Node::query()->create([
            'name' => 'homebrew-inventory-other',
            'status' => LifecycleStatus::Active,
            'platform' => 'macos',
            'architecture' => 'arm64',
            'public_ssh_host' => '127.0.0.2',
            'user' => 'mini',
            'wireguard_ip' => '10.8.0.47',
            'ssh_host_fingerprint' => 'SHA256:'.str_repeat('B', 43),
        ]);
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $cask = $node->toolManagers()->create([
            'name' => ToolManagerName::BrewCask->value,
            'status' => LifecycleStatus::Active,
        ]);
        $composer = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer->value,
            'status' => LifecycleStatus::Active,
        ]);
        $otherBrew = $other->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $formulaTool = homebrew_inventory_tool($node, $brew, 'docker');
        $caskTool = homebrew_inventory_tool($node, $cask, 'docker', ToolStatus::Failed);
        $jqTool = homebrew_inventory_tool($node, $brew, 'jq');
        homebrew_inventory_tool($node, $brew, 'yq');
        homebrew_inventory_tool($node, $composer, 'docker');
        homebrew_inventory_tool($other, $otherBrew, 'docker');
        $sha = str_repeat('ab', 32);
        $formulae = [
            homebrew_inventory_formula('docker', version: '28.0.1'),
            homebrew_inventory_formula('openssl@3', requested: false, asDependency: true, version: '3.4.0'),
            homebrew_inventory_formula('wireguard-tools'),
            homebrew_inventory_formula('wireguard-go', requested: false, asDependency: true),
            homebrew_inventory_formula('hello', tap: 'example/tap', fullName: 'hello'),
            homebrew_inventory_formula('btop', disabled: true),
            homebrew_inventory_formula('shellcheck', files: []),
            homebrew_inventory_formula('jq', version: 'latest'),
            homebrew_inventory_formula('fd', version: '1.2'),
            homebrew_inventory_formula('bat', version: '0.24.0_1'),
            homebrew_inventory_formula('ripgrep', version: '14.1.1'),
            homebrew_inventory_formula('curl', requested: true, asDependency: true, version: '8.7.1'),
            homebrew_inventory_formula('wget', version: '1.21.4', extraKeg: '1.25.0'),
            homebrew_inventory_formula('age', files: [
                'all' => homebrew_inventory_bottle('age', $sha),
            ]),
            homebrew_inventory_formula('delta', files: [
                'arm64_sequoia' => homebrew_inventory_bottle('delta', $sha),
            ]),
            homebrew_inventory_formula('eza', files: [
                'arm64_golden_gate' => [
                    'url' => 'https://example.com/eza',
                    'sha256' => $sha,
                ],
                'all' => homebrew_inventory_bottle('eza', $sha),
            ]),
        ];
        $casks = [
            homebrew_inventory_cask('font-hack', '2.0.0'),
            homebrew_inventory_cask('docker', '4.39.0', artifacts: [[
                'app' => ['Docker.app'],
                'target' => '/Applications/Docker.app',
            ]]),
            homebrew_inventory_cask('local-font', '1.0.0', url: 'file:///tmp/local-font.zip'),
            homebrew_inventory_cask('unchecked', '1.2.0', sha256: 'no_check'),
        ];
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json($formulae)),
            homebrew_inventory_names(array_map(static fn (array $formula): string => $formula['name'], $formulae)),
            homebrew_inventory_result(homebrew_inventory_json([], $casks)),
            homebrew_inventory_names(array_map(static fn (array $cask): string => $cask['token'], $casks)),
        ]);
        $managersBefore = ToolManagerRecord::query()->count();
        $toolsBefore = Tool::query()->count();
        $lock = app(NodeLocks::class)->lock('tool-manager:'.$node->id.':brew', 60);
        expect($lock->get())->toBeTrue();

        try {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $scans = $inspector->inspect($node);
            $queries = DB::getQueryLog();
        } finally {
            $lock->release();
        }

        expect($scans)->toHaveCount(2)
            ->and($scans[0]->manager)->toBe(ToolManagerName::Brew)
            ->and($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->manager)->toBe(ToolManagerName::BrewCask)
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scans[0]->packages))
            ->toBe([
                'age', 'bat', 'btop', 'curl', 'delta', 'docker', 'eza', 'fd', 'hello', 'jq',
                'openssl@3', 'ripgrep', 'shellcheck', 'wget', 'wireguard-go', 'wireguard-tools',
            ])
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scans[1]->packages))
            ->toBe(['docker', 'font-hack', 'local-font', 'unchecked']);

        $byName = [];
        foreach ($scans[0]->packages as $package) {
            $byName[$package->package] = $package;
            expect($package->manager)->toBe(ToolManagerName::Brew)
                ->and($package->packageKind)->toBe(ToolInventoryPackageKind::Formula);
        }

        expect($byName['docker']->dependency)->toBeFalse()
            ->and($byName['docker']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($byName['docker']->adoptionBlock)->toBeNull()
            ->and($byName['docker']->installedVersion)->toBe('28.0.1')
            ->and($byName['docker']->registered)->toBeTrue()
            ->and($byName['docker']->toolId)->toBe($formulaTool->id)
            ->and($byName['curl']->dependency)->toBeFalse()
            ->and($byName['curl']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($byName['openssl@3']->dependency)->toBeTrue()
            ->and($byName['openssl@3']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_DEPENDENCY)
            ->and($byName['openssl@3']->registered)->toBeFalse()
            ->and($byName['wireguard-tools']->dependency)->toBeFalse()
            ->and($byName['wireguard-tools']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_PROTECTED)
            ->and($byName['wireguard-go']->dependency)->toBeTrue()
            ->and($byName['wireguard-go']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_PROTECTED)
            ->and($byName['hello']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($byName['btop']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_ARTIFACT)
            ->and($byName['shellcheck']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_BOTTLE)
            ->and($byName['delta']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_BOTTLE)
            ->and($byName['eza']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_BOTTLE)
            ->and($byName['age']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($byName['jq']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_VERSION)
            ->and($byName['jq']->installedVersion)->toBeNull()
            ->and($byName['jq']->registered)->toBeTrue()
            ->and($byName['jq']->toolId)->toBe($jqTool->id)
            ->and(array_column($scans[0]->packages, 'package'))->not->toContain('yq')
            ->and($byName['wget']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_VERSION)
            ->and($byName['fd']->installedVersion)->toBe('1.2.0')
            ->and($byName['fd']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($byName['bat']->installedVersion)->toBeNull()
            ->and($byName['bat']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($byName['ripgrep']->registered)->toBeFalse()
            ->and($byName['ripgrep']->toolId)->toBeNull();

        $caskByName = [];
        foreach ($scans[1]->packages as $package) {
            $caskByName[$package->package] = $package;
            expect($package->dependency)->toBeFalse()
                ->and($package->packageKind)->toBe(ToolInventoryPackageKind::Cask);
        }

        expect($caskByName['docker']->toolId)->toBe($caskTool->id)
            ->and($caskByName['docker']->toolId)->not->toBe($formulaTool->id)
            ->and($caskByName['docker']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_AUTHORIZATION)
            ->and($caskByName['font-hack']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($caskByName['font-hack']->installedVersion)->toBe('2.0.0')
            ->and($caskByName['font-hack']->registered)->toBeFalse()
            ->and($caskByName['local-font']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($caskByName['unchecked']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_ARTIFACT);

        $encoded = json_encode($scans);
        expect($encoded)->not->toContain($sha)
            ->not->toContain('ghcr.io')
            ->not->toContain('/opt/homebrew')
            ->not->toContain('HOMEBREW')
            ->and(Tool::query()->count())->toBe($toolsBefore)
            ->and(ToolManagerRecord::query()->count())->toBe($managersBefore);
        foreach ($queries as $query) {
            expect(strtolower($query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
        }

        $brewArguments = homebrew_inventory_mac_arguments();
        expect($ssh->arguments())->toBe([
            ['/bin/bash', '-su', '--', 'mini'],
            ['/usr/bin/sw_vers', '-productVersion'],
            [...$brewArguments, 'info', '--json=v2', '--formula', '--installed'],
            [...$brewArguments, 'list', '--formula', '-1'],
            [...$brewArguments, 'info', '--json=v2', '--cask', '--installed'],
            [...$brewArguments, 'list', '--cask', '-1'],
        ])
            ->and($ssh->commands[0]->maxOutputBytes)->toBeNull()
            ->and($ssh->commands[2]->maxOutputBytes)->toBe(HomebrewCaskToolManager::MAX_INVENTORY_LENGTH)
            ->and($ssh->commands[3]->maxOutputBytes)->toBe(131_072)
            ->and($ssh->commands[4]->maxOutputBytes)->toBe(HomebrewCaskToolManager::MAX_INVENTORY_LENGTH)
            ->and($ssh->commands[5]->maxOutputBytes)->toBe(131_072)
            ->and($ssh->commands[0]->input)->toContain('https://github.com/Homebrew/brew')
            ->and($ssh->commands[0]->input)->not->toContain('apt-get');
        homebrew_inventory_assert_read_only($ssh);
    });

    it('uses the intel bottle tag and ignores an older tag when all is absent', function (): void {
        $sha = str_repeat('d', 64);
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/usr/local\n"),
            homebrew_inventory_result("15.4\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep', files: [
                    'sequoia' => homebrew_inventory_bottle('ripgrep', $sha),
                ]),
                homebrew_inventory_formula('wget', files: [
                    'arm64_sequoia' => homebrew_inventory_bottle('wget', $sha),
                ]),
            ])),
            homebrew_inventory_names(['ripgrep', 'wget']),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0', variations: [
                    'sequoia' => [
                        'artifacts' => [['pkg' => ['Hack.pkg']]],
                    ],
                ]),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false, architecture: 'x86_64'));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[0]->packages[0]->package)->toBe('ripgrep')
            ->and($scans[0]->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scans[0]->packages[1]->package)->toBe('wget')
            ->and($scans[0]->packages[1]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_BOTTLE)
            ->and($scans[1]->packages[0]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_AUTHORIZATION)
            ->and($ssh->arguments()[1])->toBe(['/usr/bin/sw_vers', '-productVersion'])
            ->and($ssh->arguments()[2])->toContain('PATH=/usr/local/bin:/usr/bin:/bin');
        homebrew_inventory_assert_read_only($ssh);
    });

    it('keeps an empty finished read complete and a failed read incomplete', function (CommandResult $formula, ToolInventoryScanState $state): void {
        [$inspector] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            $formula,
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe($state)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->packages[0]->package)->toBe('font-hack');
    })->with([
        'failed empty document' => [
            homebrew_inventory_result(homebrew_inventory_json(), exitCode: 1),
            ToolInventoryScanState::Incomplete,
        ],
        'malformed document' => [
            homebrew_inventory_result('{"formulae":'),
            ToolInventoryScanState::Incomplete,
        ],
        'trailing document' => [
            homebrew_inventory_result(homebrew_inventory_json().'{"formulae":[],"casks":[]}'),
            ToolInventoryScanState::Incomplete,
        ],
        'duplicate name' => [
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep'),
                homebrew_inventory_formula('ripgrep'),
            ])),
            ToolInventoryScanState::Incomplete,
        ],
        'oversized document' => [
            homebrew_inventory_result(str_repeat('{', HomebrewCaskToolManager::MAX_INVENTORY_LENGTH).'}'),
            ToolInventoryScanState::Incomplete,
        ],
    ]);

    it('marks a truncated or deeply nested formula read incomplete without dropping the cask read', function (CommandResult $formula): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            $formula,
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->packages)->toHaveCount(1);
        homebrew_inventory_assert_read_only($ssh);
    })->with([
        'truncated output' => [homebrew_inventory_result(homebrew_inventory_json(), truncated: true)],
        'nested output' => [homebrew_inventory_result(str_repeat('{"a":', 70).'1'.str_repeat('}', 70))],
    ]);

    it('marks a failed cask read incomplete without dropping a finished formula read', function (): void {
        [$inspector] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep'),
            ])),
            homebrew_inventory_names(['ripgrep']),
            homebrew_inventory_result('not-json'),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[0]->packages[0]->package)->toBe('ripgrep')
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scans[1]->packages)->toBe([]);
    });

    it('reports an absent, conflicting, or unreadable macOS prefix for both managers', function (
        CommandResult $prefix,
        ToolInventoryScanState $state,
    ): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([$prefix]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe($state)
            ->and($scans[1]->scanState)->toBe($state)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->packages)->toBe([])
            ->and($ssh->arguments())->toBe([['/bin/bash', '-su', '--', 'mini']]);
        homebrew_inventory_assert_read_only($ssh);
    })->with([
        'absent' => [homebrew_inventory_result('', 42), ToolInventoryScanState::Absent],
        'conflicting' => [homebrew_inventory_result('', 43), ToolInventoryScanState::Conflicting],
        'unreadable' => [homebrew_inventory_result('', 1), ToolInventoryScanState::Incomplete],
    ]);

    it('marks both macOS managers incomplete when the bottle tag cannot be read', function (): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result('', 1),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($ssh->arguments())->toBe([
                ['/bin/bash', '-su', '--', 'mini'],
                ['/usr/bin/sw_vers', '-productVersion'],
            ]);
    });

    it('does not run Homebrew on an unsupported platform or without a wireguard address', function (): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([]);

        $unsupported = $inspector->inspect(homebrew_inventory_node(saved: false, platform: 'darwin'));
        $offline = $inspector->inspect(homebrew_inventory_node(saved: false, wireguardIp: null));

        expect($unsupported[0]->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($unsupported[1]->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($offline[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($offline[1]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($ssh->commands)->toBe([]);
    });

    it('reads a clean official Linux prefix without repinning and leaves casks unsupported', function (): void {
        $sha = str_repeat('e', 64);
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/home/linuxbrew/.linuxbrew\n"),
            homebrew_inventory_result("x86_64\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep', tag: 'x86_64_linux', version: '14.1.1', sha: $sha),
                homebrew_inventory_formula('openssl@3', tag: 'x86_64_linux', requested: false, asDependency: true),
                homebrew_inventory_formula('wget', tag: 'arm64_linux'),
            ])),
            homebrew_inventory_names(['openssl@3', 'ripgrep', 'wget']),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false, platform: 'linux', architecture: 'x86_64', user: 'orbit'));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[0]->packages[0]->package)->toBe('openssl@3')
            ->and($scans[0]->packages[0]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_DEPENDENCY)
            ->and($scans[0]->packages[1]->package)->toBe('ripgrep')
            ->and($scans[0]->packages[1]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scans[0]->packages[1]->installedVersion)->toBe('14.1.1')
            ->and($scans[0]->packages[2]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_BOTTLE)
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($scans[1]->packages)->toBe([])
            ->and($ssh->arguments())->toBe([
                ['/bin/bash', '-seu', '--', 'orbit'],
                ['/usr/bin/uname', '-m'],
                [...homebrew_inventory_linux_arguments(), 'info', '--json=v2', '--formula', '--installed'],
                [...homebrew_inventory_linux_arguments(), 'list', '--formula', '-1'],
            ]);
        expect($ssh->commands[0]->input)
            ->toContain('root:root')
            ->toContain('https://github.com/Homebrew/brew')
            ->toContain('status --porcelain=v1 --untracked-files=all')
            ->toContain('exit 42')
            ->toContain('exit 43')
            ->not->toContain('apt-get')
            ->not->toContain('clone')
            ->not->toContain('checkout')
            ->not->toContain('fetch')
            ->not->toContain('d79ef822ab8136e393ed5f86e2b56afc68d04874');
        homebrew_inventory_assert_read_only($ssh);
        expect(json_encode($scans))->not->toContain($sha)->not->toContain('/home/linuxbrew');
    });

    it('maps a Linux bottle tag from aarch64 without bootstrapping Homebrew', function (): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/home/linuxbrew/.linuxbrew\n"),
            homebrew_inventory_result("aarch64\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep', tag: 'arm64_linux'),
            ])),
            homebrew_inventory_names(['ripgrep']),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false, platform: 'linux', architecture: 'arm64', user: 'orbit'));

        expect($scans[0]->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and(json_encode($ssh->arguments()))->not->toContain('--cask')
            ->not->toContain('sw_vers');
    });

    it('reports Linux prefix states without reading formulae', function (
        CommandResult $prefix,
        ToolInventoryScanState $state,
    ): void {
        [$inspector, $ssh] = homebrew_inventory_inspector([$prefix]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false, platform: 'linux', user: 'orbit'));

        expect($scans[0]->scanState)->toBe($state)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($ssh->arguments())->toBe([['/bin/bash', '-seu', '--', 'orbit']]);
    })->with([
        'absent' => [homebrew_inventory_result('', 42), ToolInventoryScanState::Absent],
        'conflicting' => [homebrew_inventory_result('', 43), ToolInventoryScanState::Conflicting],
        'unreadable' => [homebrew_inventory_result('', 1), ToolInventoryScanState::Incomplete],
        'wrong prefix' => [homebrew_inventory_result("/tmp/brew\n"), ToolInventoryScanState::Incomplete],
    ]);

    it('marks a Linux formula read incomplete when the architecture or inventory command fails', function (
        array $results,
    ): void {
        [$inspector, $ssh] = homebrew_inventory_inspector($results);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false, platform: 'linux', user: 'orbit'));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Unsupported);
        homebrew_inventory_assert_read_only($ssh);
    })->with([
        'uname failed' => [[
            homebrew_inventory_result("/home/linuxbrew/.linuxbrew\n"),
            homebrew_inventory_result('', 1),
        ]],
        'unknown architecture' => [[
            homebrew_inventory_result("/home/linuxbrew/.linuxbrew\n"),
            homebrew_inventory_result("riscv64\n"),
        ]],
        'failed inventory' => [[
            homebrew_inventory_result("/home/linuxbrew/.linuxbrew\n"),
            homebrew_inventory_result("x86_64\n"),
            homebrew_inventory_result(homebrew_inventory_json(), exitCode: 1),
        ]],
    ]);

    it('does not query tools for an unsaved node', function (): void {
        [$inspector] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json()),
            homebrew_inventory_names([]),
            homebrew_inventory_result(homebrew_inventory_json()),
            homebrew_inventory_names([]),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $inspector->inspect(homebrew_inventory_node(saved: false));

        expect(DB::getQueryLog())->toBe([]);
    });

    it('keeps an empty macOS read complete when both name lists are empty', function (): void {
        [$inspector] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json()),
            homebrew_inventory_names([]),
            homebrew_inventory_result(homebrew_inventory_json()),
            homebrew_inventory_names([]),
        ]);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->packages)->toBe([]);
    });

    it('keeps unloadable formula and cask names that info omits', function (): void {
        $node = homebrew_inventory_node();
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $bun = homebrew_inventory_tool($node, $brew, 'bun');
        [$inspector, $ssh] = homebrew_inventory_inspector([
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep', version: '14.1.1'),
                homebrew_inventory_formula('libsigc++', version: '3.6.0', requested: false, asDependency: true),
            ])),
            homebrew_inventory_names(['bun', 'libsigc++', 'php@8.3', 'ripgrep', 'wireguard-tools']),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack', 'logi-options+']),
        ]);

        $scans = $inspector->inspect($node);
        $formulae = [];
        foreach ($scans[0]->packages as $package) {
            $formulae[$package->package] = $package;
        }
        $casks = [];
        foreach ($scans[1]->packages as $package) {
            $casks[$package->package] = $package;
        }

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_keys($formulae))->toBe(['bun', 'libsigc++', 'php@8.3', 'ripgrep', 'wireguard-tools'])
            ->and($formulae['ripgrep']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($formulae['libsigc++']->dependency)->toBeTrue()
            ->and($formulae['libsigc++']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_DEPENDENCY)
            ->and($formulae['libsigc++']->installedVersion)->toBe('3.6.0')
            ->and($formulae['bun']->dependency)->toBeFalse()
            ->and($formulae['bun']->installedVersion)->toBeNull()
            ->and($formulae['bun']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($formulae['bun']->registered)->toBeTrue()
            ->and($formulae['bun']->toolId)->toBe($bun->id)
            ->and($formulae['php@8.3']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($formulae['php@8.3']->dependency)->toBeFalse()
            ->and($formulae['wireguard-tools']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_PROTECTED)
            ->and($formulae['wireguard-tools']->dependency)->toBeFalse()
            ->and($casks['font-hack']->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($casks['logi-options+']->dependency)->toBeFalse()
            ->and($casks['logi-options+']->installedVersion)->toBeNull()
            ->and($casks['logi-options+']->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($casks['logi-options+']->registered)->toBeFalse();
        homebrew_inventory_assert_read_only($ssh);
        expect($ssh->arguments()[3])->toContain('list', '--formula', '-1')
            ->and($ssh->arguments()[5])->toContain('list', '--cask', '-1');
    });

    it('marks the scan incomplete when the name list fails or disagrees with info', function (array $results): void {
        [$inspector, $ssh] = homebrew_inventory_inspector($results);

        $scans = $inspector->inspect(homebrew_inventory_node(saved: false));

        expect($scans[0]->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scans[0]->packages)->toBe([])
            ->and($scans[1]->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scans[1]->packages[0]->package)->toBe('font-hack');
        homebrew_inventory_assert_read_only($ssh);
    })->with([
        'list failed' => [[
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep'),
            ])),
            homebrew_inventory_result('', exitCode: 1),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]],
        'invalid name' => [[
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json()),
            homebrew_inventory_result("Foo\n"),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]],
        'duplicate name' => [[
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep'),
            ])),
            homebrew_inventory_names(['ripgrep', 'ripgrep']),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]],
        'metadata name missing from the list' => [[
            homebrew_inventory_result("/opt/homebrew\n"),
            homebrew_inventory_result("27.0.1\n"),
            homebrew_inventory_result(homebrew_inventory_json([
                homebrew_inventory_formula('ripgrep'),
            ])),
            homebrew_inventory_names(['wget']),
            homebrew_inventory_result(homebrew_inventory_json([], [
                homebrew_inventory_cask('font-hack', '2.0.0'),
            ])),
            homebrew_inventory_names(['font-hack']),
        ]],
    ]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{HomebrewInventoryInspector, ToolManagerFakeSshExecutor}
 */
function homebrew_inventory_inspector(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    $commands = new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: homebrew_inventory_keys(),
        knownHosts: homebrew_inventory_known_hosts(),
    );
    $versions = new SemverVersionNormalizer;

    return [
        new HomebrewInventoryInspector(
            commands: $commands,
            versions: $versions,
            formulae: new HomebrewToolManager($commands, $versions),
            casks: new HomebrewCaskToolManager($commands, $versions),
        ),
        $ssh,
    ];
}

function homebrew_inventory_node(
    bool $saved = true,
    string $platform = 'macos',
    string $architecture = 'arm64',
    string $user = 'mini',
    ?string $wireguardIp = '10.8.0.45',
): Node {
    $attributes = [
        'name' => 'homebrew-inventory-node',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => $wireguardIp,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ];

    if (! $saved) {
        return new Node($attributes);
    }

    return Node::query()->create($attributes);
}

function homebrew_inventory_tool(
    Node $node,
    ToolManagerRecord $manager,
    string $package,
    ToolStatus $status = ToolStatus::Installed,
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'status' => $status,
        'installed_version' => '1.0.0',
    ]);
}

function homebrew_inventory_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    expect(json_encode($ssh->arguments()))
        ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE')
        ->not->toContain('apt-get')
        ->not->toContain('git clone')
        ->not->toContain('brew update');

    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('update')
            ->not->toContain('install')
            ->not->toContain('upgrade')
            ->not->toContain('uninstall')
            ->not->toContain('ruby')
            ->not->toContain('--zap')
            ->not->toContain('autoremove')
            ->not->toContain('services')
            ->not->toContain('sudo');
    }

    foreach ($ssh->commands as $command) {
        expect($command->input ?? '')
            ->not->toContain('apt-get')
            ->not->toContain('git clone')
            ->not->toContain('checkout')
            ->not->toContain('fetch')
            ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE')
            ->not->toContain('d79ef822ab8136e393ed5f86e2b56afc68d04874');
    }
}

/** @param list<string> $names */
function homebrew_inventory_names(array $names): CommandResult
{
    return homebrew_inventory_result($names === [] ? '' : implode("\n", $names)."\n");
}

function homebrew_inventory_result(
    string $stdout = '',
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 10, $truncated);
}

/**
 * @param  list<array<string, mixed>>  $formulae
 * @param  list<array<string, mixed>>  $casks
 */
function homebrew_inventory_json(array $formulae = [], array $casks = []): string
{
    return json_encode([
        'formulae' => $formulae,
        'casks' => $casks,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>|null  $files
 * @return array<string, mixed>
 */
function homebrew_inventory_formula(
    string $name,
    string $version = '1.0.0',
    bool $requested = true,
    bool $asDependency = false,
    ?string $tap = null,
    ?string $fullName = null,
    bool $disabled = false,
    ?array $files = null,
    ?string $tag = null,
    ?string $sha = null,
    ?string $extraKeg = null,
): array {
    $sha ??= str_repeat('ab', 32);
    $tag ??= 'arm64_golden_gate';
    $bottleFiles = $files ?? [$tag => homebrew_inventory_bottle($name, $sha)];
    $kegs = [[
        'version' => $version,
        'installed_on_request' => $requested,
        'installed_as_dependency' => $asDependency,
    ]];

    if ($extraKeg !== null) {
        $kegs[] = [
            'version' => $extraKeg,
            'installed_on_request' => $requested,
            'installed_as_dependency' => $asDependency,
        ];
    }

    return [
        'name' => $name,
        'full_name' => $fullName ?? $name,
        'tap' => $tap ?? 'homebrew/core',
        'versions' => ['stable' => '9.9.9', 'bottle' => true],
        'bottle' => ['stable' => ['files' => $bottleFiles === [] ? new stdClass : $bottleFiles]],
        'disabled' => $disabled,
        'installed' => $kegs,
    ];
}

/** @return array{url: string, sha256: string} */
function homebrew_inventory_bottle(string $name, string $sha): array
{
    return [
        'url' => "https://ghcr.io/v2/homebrew/core/{$name}/blobs/sha256:{$sha}",
        'sha256' => $sha,
    ];
}

/**
 * @param  array<string, mixed>|null  $artifacts
 * @param  array<string, mixed>|null  $variations
 * @return array<string, mixed>
 */
function homebrew_inventory_cask(
    string $token,
    string $version,
    ?array $artifacts = null,
    ?string $url = null,
    ?string $sha256 = null,
    ?array $variations = null,
): array {
    return [
        'token' => $token,
        'full_token' => $token,
        'tap' => 'homebrew/cask',
        'url' => $url ?? "https://example.com/{$token}.zip",
        'version' => $version,
        'installed' => $version,
        'sha256' => $sha256 ?? str_repeat('a', 64),
        'disabled' => false,
        'variations' => $variations ?? new stdClass,
        'artifacts' => $artifacts ?? [[
            'font' => ["{$token}.ttf"],
            'target' => "/\$HOME/Library/Fonts/{$token}.ttf",
        ]],
    ];
}

/** @return non-empty-list<string> */
function homebrew_inventory_mac_arguments(string $prefix = '/opt/homebrew'): array
{
    return [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
        'HOMEBREW_NO_INSTALL_CLEANUP=1',
        'PATH='.$prefix.'/bin:/usr/bin:/bin',
        $prefix.'/bin/brew',
    ];
}

/** @return non-empty-list<string> */
function homebrew_inventory_linux_arguments(): array
{
    return [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
        'HOMEBREW_NO_INSTALL_CLEANUP=1',
        'PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin',
        '/home/linuxbrew/.linuxbrew/bin/brew',
    ];
}

function homebrew_inventory_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAATEST orbit@test';
        }
    };
}

function homebrew_inventory_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
