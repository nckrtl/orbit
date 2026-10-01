<?php

declare(strict_types=1);

use App\Actions\Tools\InstallToolAction;
use App\Actions\Tools\RemoveToolAction;
use App\Actions\Tools\UpdateToolAction;
use App\Data\Tools\InstallToolData;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\HomebrewCaskDiscovery;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\Tool;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ImmediateToolOperationLock;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(HomebrewCaskToolManager::class, function (): void {
    it('is a macOS-only manager and rejects taps, urls, local files, and options', function (string $package, bool $valid): void {
        [$manager] = cask_manager([]);

        expect($manager->name())->toBe(ToolManagerName::BrewCask)
            ->and($manager->supportsNode(cask_node('linux')))->toBeFalse()
            ->and($manager->supportsNode(cask_node()))->toBeTrue()
            ->and($manager->validatePackage($package))->toBe($valid);
    })->with([
        'token' => ['font-hack', true],
        'versioned token' => ['firefox@esr', true],
        'trailing plus' => ['logi-options+', true],
        'repeated trailing plus' => ['starnet++', true],
        'trailing hyphen' => ['logi-options-', false],
        'empty' => ['', false],
        'uppercase' => ['Font-Hack', false],
        'tap' => ['homebrew/cask/font-hack', false],
        'url' => ['https://example.com/font-hack.rb', false],
        'local file' => ['./font-hack.rb', false],
        'absolute file' => ['/tmp/font-hack.rb', false],
        'zap option' => ['--zap', false],
        'cask option' => ['--cask', false],
    ]);

    it('installs, updates, and removes one cask with fixed official commands', function (): void {
        $node = cask_node();
        $metadata = cask_result(cask_metadata());
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_result("Homebrew 4.6.15\n"),
            cask_prefix(),
            cask_product(),
            $metadata,
            cask_prefix(),
            cask_result(exitCode: 1),
            cask_result(cask_installed_names(['1password-cli', 'docker-desktop'])),
            cask_prefix(),
            cask_product(),
            $metadata,
            cask_prefix(),
            cask_result(),
            cask_prefix(),
            cask_product(),
            $metadata,
            cask_prefix(),
            cask_result(),
            cask_prefix(),
            cask_product(),
            $metadata,
            cask_prefix(),
            cask_result(),
        ]);

        expect($manager->managerVersion($node))->toBe('Homebrew 4.6.15')
            ->and($manager->candidateVersion($node, 'font-hack', ToolOperation::Install))->toBe('3.003')
            ->and($manager->installedVersion($node, 'font-hack'))->toBeNull()
            ->and($manager->planRemoval($node, 'font-hack')->packages)->toBe(['font-hack']);
        $manager->install($node, 'font-hack');
        $manager->update($node, 'font-hack');
        $manager->remove($node, 'font-hack');

        $plain = cask_arguments();
        $refresh = cask_arguments(refreshApi: true);
        $coordinate = 'homebrew/cask/font-hack';
        $info = ['info', '--json=v2', '--cask', $coordinate];

        expect($ssh->arguments())->toBe([
            ['/bin/bash', '-su', '--', 'mini'],
            [...$plain, '--version'],
            ['/bin/bash', '-su', '--', 'mini'],
            ['/usr/bin/sw_vers', '-productVersion'],
            [...$refresh, ...$info],
            ['/bin/bash', '-su', '--', 'mini'],
            [...$plain, 'list', '--versions', '--cask', 'font-hack'],
            [...$plain, 'info', '--json=v2', '--cask', '--installed'],
            ['/bin/bash', '-su', '--', 'mini'],
            ['/usr/bin/sw_vers', '-productVersion'],
            [...$refresh, ...$info],
            ['/bin/bash', '-su', '--', 'mini'],
            [...$refresh, 'install', '--cask', $coordinate],
            ['/bin/bash', '-su', '--', 'mini'],
            ['/usr/bin/sw_vers', '-productVersion'],
            [...$refresh, ...$info],
            ['/bin/bash', '-su', '--', 'mini'],
            [...$refresh, 'upgrade', '--cask', $coordinate],
            ['/bin/bash', '-su', '--', 'mini'],
            ['/usr/bin/sw_vers', '-productVersion'],
            [...$plain, ...$info],
            ['/bin/bash', '-su', '--', 'mini'],
            [...$plain, 'uninstall', '--cask', $coordinate],
        ]);
        expect(json_encode($ssh->arguments()))
            ->not->toContain('--zap')
            ->not->toContain('autoremove')
            ->not->toContain('services')
            ->not->toContain('brew ruby')
            ->not->toContain('--formula')
            ->not->toContain('homebrew/core/font-hack');
    });

    it('keeps a same-named formula and cask on different coordinates', function (): void {
        $node = cask_node();
        [$formula, $formulaSsh] = cask_formula_manager([
            cask_prefix(),
            cask_result("27.0.1\n"),
            cask_result(cask_formula_metadata()),
            cask_prefix(),
            cask_result(),
        ]);
        [$cask, $caskSsh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata(['token' => 'docker'])),
            cask_prefix(),
            cask_result(),
        ]);

        $formula->install($node, 'docker');
        $cask->install($node, 'docker');

        expect(json_encode($formulaSsh->arguments()))
            ->toContain('homebrew\/core\/docker')
            ->toContain('--formula')
            ->not->toContain('--cask')
            ->and(json_encode($caskSsh->arguments()))
            ->toContain('homebrew\/cask\/docker')
            ->toContain('--cask')
            ->not->toContain('--formula')
            ->not->toContain('homebrew\/core\/docker');
    });

    it('stores a formula and a cask with the same name as different tools', function (): void {
        $node = cask_saved_node();
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew,
            'status' => LifecycleStatus::Active,
        ]);
        $cask = $node->toolManagers()->create([
            'name' => ToolManagerName::BrewCask,
            'status' => LifecycleStatus::Active,
        ]);
        $formula = $node->tools()->create([
            'tool_manager_id' => $brew->id,
            'package' => 'docker',
            'status' => ToolStatus::Installed,
            'installed_version' => '28.0.0',
        ]);
        $caskTool = $node->tools()->create([
            'tool_manager_id' => $cask->id,
            'package' => 'docker',
            'status' => ToolStatus::Installed,
            'installed_version' => '4.34.0',
        ]);

        expect($brew->id)->not->toBe($cask->id)
            ->and($formula->id)->not->toBe($caskTool->id)
            ->and(Tool::query()->where('package', 'docker')->count())->toBe(2);
    });

    it('refuses unsupported cask metadata before any mutation', function (array $changes, string $message): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata($changes)),
        ]);

        $exception = cask_exception(
            fn () => $manager->install(cask_node(), 'font-hack'),
        );

        expect($exception->getMessage())->toBe($message)
            ->and($exception->result?->stdout)->toBe('')
            ->and($exception->getMessage())->not->toContain('secret-cask-output')
            ->and(json_encode($ssh->arguments()))->not->toContain('install');
    })->with([
        'preflight artifact' => [
            ['artifacts' => [['preflight' => null], ['font' => ['Hack.ttf'], 'target' => '/$HOME/Library/Fonts/Hack.ttf']]],
            'The Homebrew cask artifact is not supported.',
        ],
        'disabled cask' => [
            ['disabled' => true],
            'The Homebrew cask artifact is not supported.',
        ],
        'application target' => [
            ['artifacts' => [['app' => ['Font Hack.app'], 'target' => '/Applications/Font Hack.app']]],
            'The Homebrew cask requires interactive or administrator authorization.',
        ],
        'package installer' => [
            ['artifacts' => [['pkg' => ['FontHack.pkg']]]],
            'The Homebrew cask requires interactive or administrator authorization.',
        ],
        'privileged uninstall' => [
            ['artifacts' => [
                ['font' => ['Hack.ttf'], 'target' => '/$HOME/Library/Fonts/Hack.ttf'],
                ['uninstall' => [['pkgutil' => 'com.example.font-hack']]],
            ]],
            'The Homebrew cask requires interactive or administrator authorization.',
        ],
        'checksum no_check' => [
            ['sha256' => 'no_check'],
            'The Homebrew cask checksum policy was not satisfied.',
        ],
        'file url' => [
            ['url' => 'file:///tmp/font-hack.zip'],
            'The Homebrew cask is not an official Homebrew cask.',
        ],
        'third-party tap' => [
            ['tap' => 'homebrew/cask-versions'],
            'The Homebrew cask is not an official Homebrew cask.',
        ],
        'qualified identity' => [
            ['full_token' => 'homebrew/cask/font-hack'],
            'The Homebrew cask is not an official Homebrew cask.',
        ],
        'latest version' => [
            ['version' => 'latest'],
            'The Homebrew cask version cannot be read.',
        ],
    ]);

    it('applies the node bottle variation and ignores zap', function (
        string $architecture,
        string $product,
        array $variations,
        ?string $version,
        ?string $message,
    ): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product($product),
            cask_result(cask_metadata([
                'sha256' => 'no_check',
                'artifacts' => [
                    ['font' => ['Hack.ttf'], 'target' => '/$HOME/Library/Fonts/Hack.ttf'],
                    ['zap' => [['pkgutil' => 'com.example.should-not-block', 'delete' => '/Library/Privileged']]],
                ],
                'variations' => $variations,
            ])),
        ]);
        $node = cask_node(architecture: $architecture);

        if ($message === null) {
            expect($manager->candidateVersion($node, 'font-hack', ToolOperation::Update))->toBe($version);

            return;
        }

        expect(fn () => $manager->candidateVersion($node, 'font-hack', ToolOperation::Update))
            ->toThrow(ToolManagerException::class, $message);
        expect(json_encode($ssh->arguments()))->not->toContain('upgrade');
    })->with([
        'arm64 base ignores an intel variation' => [
            'arm64',
            "27.0.1\n",
            ['golden_gate' => [
                'sha256' => str_repeat('b', 64),
                'version' => '9.1.0',
                'url' => 'https://example.com/intel.zip',
                'artifacts' => [['pkg' => ['Intel.pkg']]],
            ]],
            null,
            'The Homebrew cask checksum policy was not satisfied.',
        ],
        'intel tag overrides the arm64 base' => [
            'x86_64',
            "27.0.1\n",
            ['golden_gate' => [
                'sha256' => str_repeat('b', 64),
                'version' => '9.1.0',
                'url' => 'https://example.com/intel.zip',
            ]],
            '9.1.0',
            null,
        ],
        'older arm64 tag overrides the base' => [
            'arm64',
            "15.6.1\n",
            ['arm64_sequoia' => [
                'sha256' => str_repeat('c', 64),
                'version' => '2.0.0',
                'url' => 'https://example.com/sequoia.zip',
                'artifacts' => [['pkg' => ['Sequoia.pkg']]],
            ]],
            null,
            'The Homebrew cask requires interactive or administrator authorization.',
        ],
    ]);

    it('redacts malformed cask metadata', function (): void {
        [$manager] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result('secret-cask-output', exitCode: 0),
        ]);

        $exception = cask_exception(
            fn () => $manager->candidateVersion(cask_node(), 'font-hack', ToolOperation::Install),
        );
        $exported = var_export($exception->__debugInfo(), return: true);

        expect($exception->getMessage())->toBe('The Homebrew cask metadata was malformed.')
            ->and($exception->result?->stdout)->toBe('')
            ->and($exported)->not->toContain('secret-cask-output');
    });

    it('returns null only for an official cask that brew cannot find', function (string $stderr): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result(exitCode: 1, stderr: $stderr),
        ]);

        expect($manager->candidateVersion(cask_node(), 'missing', ToolOperation::Install))->toBeNull()
            ->and($ssh->arguments())->not->toBeEmpty();
    })->with([
        'untapped official cask' => [cask_tap_unavailable('missing')],
        'older missing-cask text' => ["Error: Cask 'homebrew/cask/missing' is unavailable: No Cask with this name exists.\n"],
    ]);

    it('reads the installed version from the unqualified cask token', function (): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_result("1password-cli 2.33.0\n"),
        ]);

        expect($manager->installedVersion(cask_node(), '1password-cli'))->toBe('2.33.0')
            ->and($ssh->arguments()[1])->toBe([...cask_arguments(), 'list', '--versions', '--cask', '1password-cli']);
    });

    it('confirms a silent cask list failure before treating the cask as absent', function (array $names, string $package, ?string $version): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_result(exitCode: 1),
            cask_result(cask_installed_names($names)),
        ]);

        expect($manager->installedVersion(cask_node(), $package))->toBe($version)
            ->and($ssh->arguments()[2])->toBe([...cask_arguments(), 'info', '--json=v2', '--cask', '--installed']);
    })->with([
        'absent official cask' => [['1password-cli', 'docker-desktop'], 'font-hack', null],
        'nonexistent token' => [['1password-cli', 'docker-desktop'], 'not-a-cask', null],
        'official cask still installed' => [['1password-cli', 'docker-desktop'], '1password-cli', '2.33.0'],
    ]);

    it('does not report a same-token third-party cask as the official tool', function (): void {
        [$manager] = cask_manager([
            cask_prefix(),
            cask_result(exitCode: 1),
            cask_result(cask_metadata([
                'token' => 'font-hack',
                'tap' => 'someone/cask',
                'installed' => '3.003',
            ])),
        ]);

        expect($manager->installedVersion(cask_node(), 'font-hack'))->toBeNull();
    });

    it('does not treat a noisy cask list failure as an absent cask', function (): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_result(exitCode: 1, stderr: "Error: Cask 'font-hack' is not installed.\n"),
        ]);

        expect(fn () => $manager->installedVersion(cask_node(), 'font-hack'))
            ->toThrow(ToolManagerException::class, 'installed version probe failed');
        expect($ssh->arguments())->toHaveCount(2);
    });

    it('does not run a command for a linux node, a bad name, a bad cpu, or removal candidate', function (callable $run): void {
        [$manager, $ssh] = cask_manager([]);

        expect(fn () => $run($manager))->toThrow(ToolManagerException::class);
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'linux' => [fn (HomebrewCaskToolManager $manager) => $manager->install(cask_node('linux'), 'font-hack')],
        'tap name' => [fn (HomebrewCaskToolManager $manager) => $manager->install(cask_node(), 'homebrew/cask/font-hack')],
        'cpu' => [fn (HomebrewCaskToolManager $manager) => $manager->install(cask_node(architecture: 'i386'), 'font-hack')],
        'removal candidate' => [fn (HomebrewCaskToolManager $manager) => $manager->candidateVersion(cask_node(), 'font-hack', ToolOperation::Remove)],
    ]);

    it('lists unsupported installed casks without adopting them', function (): void {
        $sha = str_repeat('a', 64);
        $inventory = json_encode([
            'formulae' => [],
            'casks' => [
                cask_document(['installed' => '3.003']),
                cask_document([
                    'token' => 'firefox',
                    'installed' => '128.0.3',
                    'artifacts' => [['app' => ['Firefox.app'], 'target' => '/Applications/Firefox.app']],
                ]),
                cask_document([
                    'token' => 'local-font',
                    'installed' => '1.0.0',
                    'url' => 'file:///tmp/local-font.zip',
                ]),
                cask_document([
                    'token' => 'staged',
                    'installed' => '1.0.0',
                    'artifacts' => [['stage_only' => true]],
                ]),
                cask_document([
                    'token' => 'unchecked',
                    'installed' => '1.2.0',
                    'sha256' => 'no_check',
                ]),
                cask_document([
                    'token' => 'rolling',
                    'installed' => 'latest',
                    'version' => 'latest',
                ]),
                cask_document([
                    'token' => 'docker-desktop',
                    'installed' => '4.93.0',
                    'variations' => [
                        'arm64_golden_gate' => [
                            'artifacts' => [['pkg' => ['Docker.pkg']]],
                        ],
                    ],
                ]),
            ],
        ], JSON_THROW_ON_ERROR);
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result($inventory),
        ]);
        $held = Cache::lock('orbit:tool-manager:909001:brew', 60);
        expect($held->get())->toBeTrue();

        try {
            $discovered = $manager->discoverInstalled(cask_node());
        } finally {
            $held->release();
        }

        expect($discovered)->toHaveCount(7)
            ->and($discovered[0]->adoption)->toBe(HomebrewCaskDiscovery::SUPPORTED)
            ->and($discovered[0]->adoptionBlock)->toBeNull()
            ->and($discovered[0]->installedVersion)->toBe('3.003')
            ->and($discovered[1]->package)->toBe('firefox')
            ->and($discovered[1]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_AUTHORIZATION)
            ->and($discovered[2]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_SOURCE)
            ->and($discovered[3]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_ARTIFACT)
            ->and($discovered[4]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_ARTIFACT)
            ->and($discovered[5]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_VERSION)
            ->and($discovered[6]->package)->toBe('docker-desktop')
            ->and($discovered[6]->adoptionBlock)->toBe(HomebrewCaskDiscovery::BLOCK_AUTHORIZATION)
            ->and(Tool::query()->count())->toBe(0)
            ->and(json_encode($ssh->arguments()))
            ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE')
            ->not->toContain('--zap')
            ->not->toContain('"install"');
    });

    it('retries a failed cask install without keeping raw metadata', function (): void {
        $node = cask_saved_node();
        $secretMetadata = cask_metadata([
            'sha256' => 'no_check',
            'url' => 'https://example.com/secret-cask-output.zip',
        ]);
        [$manager, $ssh] = cask_manager([
            ...cask_install_probe($secretMetadata),
            ...cask_install_probe(cask_metadata(), '3.003'),
        ]);
        $install = cask_install_action($manager);

        $failure = cask_operation_exception(
            fn () => $install->execute(new InstallToolData($node->id, 'brew-cask', 'font-hack', null)),
        );
        $tool = Tool::query()->sole();
        $exported = var_export($failure->__debugInfo(), return: true);

        expect($failure->errorCode)->toBe('tool.install_failed')
            ->and($failure->status)->toBe(502)
            ->and($failure->getMessage())->not->toContain('secret-cask-output')
            ->and($exported)->not->toContain('secret-cask-output')
            ->and($tool->status)->toBe(ToolStatus::Failed)
            ->and($tool->error_code)->toBe('tool.install_failed')
            ->and($tool->failed_operation)->toBe(ToolOperation::Install)
            ->and(json_encode($ssh->arguments()))->not->toContain('"install"');

        $result = $install->execute(new InstallToolData($node->id, 'brew-cask', 'font-hack', null));

        expect($result->outcome)->toBe(ToolOutcome::Applied)
            ->and($result->tool->status)->toBe(ToolStatus::Installed)
            ->and($result->tool->installed_version)->toBe('3.003')
            ->and($result->tool->manager->name)->toBe('brew-cask');
    });

    it('keeps a tool failed when update or removal needs administrator authorization', function (string $operation): void {
        $node = cask_saved_node();
        $record = $node->toolManagers()->create([
            'name' => ToolManagerName::BrewCask,
            'status' => LifecycleStatus::Active,
            'installed_version' => 'Homebrew 4.6.15',
        ]);
        $tool = $node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'font-hack',
            'status' => ToolStatus::Installed,
            'installed_version' => '3.003',
        ]);
        $metadata = cask_metadata([
            'artifacts' => [['pkg' => ['FontHack.pkg']]],
            'url' => 'https://example.com/secret-cask-output.pkg',
        ]);
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_result("font-hack 3.003\n"),
            cask_prefix(),
            cask_product(),
            cask_result($metadata),
        ]);
        $action = $operation === 'update'
            ? cask_update_action($manager)
            : cask_remove_action($manager);

        $failure = cask_operation_exception(fn () => $action->execute($tool));
        $tool->refresh();
        $rendered = json_encode($ssh->arguments());

        expect($failure->errorCode)->toBe($operation === 'update' ? 'tool.update_failed' : 'tool.remove_failed')
            ->and($failure->getMessage())->not->toContain('secret-cask-output')
            ->and(var_export($failure->__debugInfo(), return: true))->not->toContain('secret-cask-output')
            ->and($tool->status)->toBe(ToolStatus::Failed)
            ->and($tool->error_code)->toBe($failure->errorCode)
            ->and($rendered)->not->toContain('upgrade')
            ->and($rendered)->not->toContain('uninstall')
            ->and($rendered)->not->toContain('--zap');
    })->with([
        'update' => ['update'],
        'removal' => ['remove'],
    ]);

    it('refuses removal when only the node variation needs authorization', function (): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata([
                'variations' => [
                    'golden_gate' => [
                        'artifacts' => [
                            ['font' => ['Hack.ttf'], 'target' => '/$HOME/Library/Fonts/Hack.ttf'],
                            ['uninstall' => [['pkgutil' => 'com.example.font-hack']]],
                        ],
                    ],
                ],
            ])),
        ]);

        expect(fn () => $manager->remove(cask_node(architecture: 'x86_64'), 'font-hack'))
            ->toThrow(ToolManagerException::class, 'administrator authorization');
        expect(json_encode($ssh->arguments()))->not->toContain('uninstall');
    });

    it('deletes a tool when removal is safe despite install-time metadata', function (array $results, string $target): void {
        $node = cask_saved_node();
        $record = $node->toolManagers()->create([
            'name' => ToolManagerName::BrewCask,
            'status' => LifecycleStatus::Active,
            'installed_version' => 'Homebrew 4.6.15',
        ]);
        $tool = $node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'font-hack',
            'status' => ToolStatus::Installed,
            'installed_version' => '3.003',
        ]);
        [$manager, $ssh] = cask_manager($results);

        $result = cask_remove_action($manager)->execute($tool);

        $uninstall = null;

        foreach ($ssh->arguments() as $command) {
            if (in_array('uninstall', $command, true)) {
                $uninstall = $command;
            }
        }

        expect($result->outcome)->toBe(ToolOutcome::Applied)
            ->and(Tool::query()->whereKey($tool->id)->exists())->toBeFalse()
            ->and($uninstall)->toContain($target)
            ->and(json_encode($ssh->arguments()))->not->toContain('--zap');

        if ($target === 'font-hack') {
            expect($uninstall)->not->toContain('homebrew/cask/font-hack');
        }
    })->with([
        'disabled cask' => [[
            cask_prefix(),
            cask_result("font-hack 3.003\n"),
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata([
                'disabled' => true,
                'sha256' => 'no_check',
                'version' => 'latest',
            ])),
            cask_prefix(),
            cask_result(),
            cask_prefix(),
            cask_result(exitCode: 1),
            cask_result(cask_installed_names(['1password-cli', 'docker-desktop'])),
        ], 'homebrew/cask/font-hack'],
        'delisted cask' => [[
            cask_prefix(),
            cask_result("font-hack 3.003\n"),
            cask_prefix(),
            cask_product(),
            cask_result(exitCode: 1, stderr: cask_tap_unavailable('font-hack')),
            cask_result(cask_metadata([
                'installed' => '3.003',
                'disabled' => true,
                'sha256' => 'no_check',
                'version' => 'latest',
            ])),
            cask_prefix(),
            cask_result(),
            cask_prefix(),
            cask_result(exitCode: 1),
            cask_result(cask_installed_names(['1password-cli', 'docker-desktop'])),
        ], 'font-hack'],
    ]);

    it('does not uninstall a delisted cask from another tap', function (): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_product(),
            cask_result(exitCode: 1, stderr: cask_tap_unavailable('font-hack')),
            cask_result(cask_metadata([
                'tap' => 'someone/cask',
                'installed' => '3.003',
            ])),
        ]);

        expect(fn () => $manager->remove(cask_node(), 'font-hack'))
            ->toThrow(ToolManagerException::class, 'not an official Homebrew cask');
        expect(json_encode($ssh->arguments()))->not->toContain('uninstall');
    });

    it('adopts an official cask from live metadata and refuses one that needs authorization', function (): void {
        [$manager, $ssh] = cask_manager([
            cask_prefix(),
            cask_prefix(),
            cask_result("font-hack 3.003\n"),
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata()),
            cask_prefix(),
            cask_prefix(),
            cask_result("docker 4.39.0\n"),
            cask_prefix(),
            cask_product(),
            cask_result(cask_metadata([
                'token' => 'docker',
                'artifacts' => [['pkg' => ['Docker.pkg']]],
            ])),
        ]);
        $node = cask_node();

        expect($manager->inspectForAdoption($node, 'font-hack'))
            ->toEqual(new ToolAdoptionFact('3.003', null))
            ->and($manager->inspectForAdoption($node, 'docker'))
            ->toEqual(new ToolAdoptionFact('4.39.0', ToolInventoryPackage::BLOCK_AUTHORIZATION))
            ->and(json_encode($ssh->arguments()))
            ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE')
            ->not->toContain('install')
            ->not->toContain('upgrade')
            ->not->toContain('uninstall')
            ->not->toContain('--zap');
    });
});

/**
 * @param  list<CommandResult>  $results
 * @return array{HomebrewCaskToolManager, ToolManagerFakeSshExecutor}
 */
function cask_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    $commands = cask_runner($ssh);

    return [new HomebrewCaskToolManager($commands, new SemverVersionNormalizer), $ssh];
}

/**
 * @param  list<CommandResult>  $results
 * @return array{HomebrewToolManager, ToolManagerFakeSshExecutor}
 */
function cask_formula_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [new HomebrewToolManager(cask_runner($ssh), new SemverVersionNormalizer), $ssh];
}

function cask_runner(ToolManagerFakeSshExecutor $ssh): RemoteToolCommandRunner
{
    return new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAATEST orbit@test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

function cask_node(string $platform = 'macos', string $architecture = 'arm64'): Node
{
    return new Node([
        'name' => 'cask-node',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.45',
    ]);
}

function cask_saved_node(): Node
{
    return Node::query()->create([
        'name' => 'cask-saved-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.46',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}

function cask_prefix(): CommandResult
{
    return cask_result("/opt/homebrew\n");
}

function cask_product(string $version = "27.0.1\n"): CommandResult
{
    return cask_result($version);
}

/** @param  list<string>  $names */
function cask_installed_names(array $names): string
{
    return json_encode([
        'formulae' => [],
        'casks' => array_map(
            fn (string $name): array => cask_document([
                'token' => $name,
                'installed' => $name === '1password-cli' ? '2.33.0' : '4.93.0',
            ]),
            $names,
        ),
    ], JSON_THROW_ON_ERROR);
}

function cask_tap_unavailable(string $token): string
{
    $coordinate = "homebrew/cask/{$token}";

    return <<<STDERR
Error: Cask '{$coordinate}' is unavailable.
This command requires the tap homebrew/cask.
If you trust this tap, tap it explicitly and then try again:
  brew tap homebrew/cask
STDERR;
}

function cask_result(string $stdout = '', int $exitCode = 0, string $stderr = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, $stderr, 10, false);
}

/** @param array<string, mixed> $changes */
function cask_metadata(array $changes = []): string
{
    return json_encode([
        'formulae' => [],
        'casks' => [cask_document($changes)],
    ], JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $changes */
function cask_document(array $changes = []): array
{
    $token = is_string($changes['token'] ?? null) ? $changes['token'] : 'font-hack';

    return [
        'token' => $token,
        'full_token' => $changes['full_token'] ?? $token,
        'tap' => $changes['tap'] ?? 'homebrew/cask',
        'url' => $changes['url'] ?? 'https://example.com/font-hack.zip',
        'version' => array_key_exists('version', $changes) ? $changes['version'] : '3.003',
        'installed' => $changes['installed'] ?? null,
        'sha256' => array_key_exists('sha256', $changes) ? $changes['sha256'] : str_repeat('a', 64),
        'disabled' => $changes['disabled'] ?? false,
        'variations' => $changes['variations'] ?? new stdClass,
        'artifacts' => $changes['artifacts'] ?? [[
            'font' => ['Hack-Regular.ttf'],
            'target' => '/$HOME/Library/Fonts/Hack-Regular.ttf',
        ]],
    ];
}

function cask_formula_metadata(): string
{
    $sha = str_repeat('a', 64);

    return json_encode([
        'formulae' => [[
            'name' => 'docker',
            'full_name' => 'docker',
            'tap' => 'homebrew/core',
            'versions' => ['stable' => '28.0.0', 'bottle' => true],
            'bottle' => ['stable' => ['files' => [
                'arm64_golden_gate' => [
                    'url' => "https://ghcr.io/v2/homebrew/core/docker/blobs/sha256:{$sha}",
                    'sha256' => $sha,
                ],
            ]]],
            'disabled' => false,
        ]],
        'casks' => [],
    ], JSON_THROW_ON_ERROR);
}

/** @return non-empty-list<string> */
function cask_arguments(bool $refreshApi = false): array
{
    $arguments = [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
        'HOMEBREW_NO_INSTALL_CLEANUP=1',
    ];

    if ($refreshApi) {
        $arguments[] = 'HOMEBREW_FORCE_API_AUTO_UPDATE=1';
    }

    $arguments[] = 'PATH=/opt/homebrew/bin:/usr/bin:/bin';
    $arguments[] = '/opt/homebrew/bin/brew';

    return $arguments;
}

/** @return list<CommandResult> */
function cask_install_probe(string $metadata, ?string $installedAfter = null): array
{
    $results = [
        cask_prefix(),
        cask_prefix(),
        cask_result("Homebrew 4.6.15\n"),
        cask_prefix(),
        cask_result(exitCode: 1),
        cask_result(cask_installed_names(['1password-cli', 'docker-desktop'])),
        cask_prefix(),
        cask_product(),
        cask_result($metadata),
    ];

    if ($installedAfter === null) {
        return $results;
    }

    return [
        ...$results,
        cask_prefix(),
        cask_result(),
        cask_prefix(),
        cask_result("font-hack {$installedAfter}\n"),
    ];
}

function cask_install_action(HomebrewCaskToolManager $manager): InstallToolAction
{
    $registry = new ToolManagerRegistry([$manager]);

    return new InstallToolAction(
        managers: $registry,
        constraints: new VersionConstraint,
        lock: new ImmediateToolOperationLock,
        materializer: new NativeToolManagerMaterializer($registry, new NativeToolManagerScopeLock),
        eligibility: new ToolNodeEligibility,
    );
}

function cask_update_action(HomebrewCaskToolManager $manager): UpdateToolAction
{
    return new UpdateToolAction(
        managers: new ToolManagerRegistry([$manager]),
        constraints: new VersionConstraint,
        lock: new ImmediateToolOperationLock,
        eligibility: new ToolNodeEligibility,
    );
}

function cask_remove_action(HomebrewCaskToolManager $manager): RemoveToolAction
{
    return new RemoveToolAction(
        managers: new ToolManagerRegistry([$manager]),
        lock: new ImmediateToolOperationLock,
        eligibility: new ToolNodeEligibility,
    );
}

function cask_exception(callable $callback): ToolManagerException
{
    try {
        $callback();
    } catch (ToolManagerException $exception) {
        return $exception;
    }

    throw new RuntimeException('The Homebrew cask operation succeeded.');
}

function cask_operation_exception(callable $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        return $exception;
    }

    throw new RuntimeException('The Homebrew cask tool operation succeeded.');
}
