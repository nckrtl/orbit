<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(VpToolManager::class, function (): void {
    it('implements the VP tool manager adapter', function (): void {
        expect(new VpToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: new ToolManagerFakeSshExecutor,
                keys: vp_tool_keys(),
                knownHosts: vp_tool_known_hosts(),
            ),
            versions: new SemverVersionNormalizer,
        ))->toBeInstanceOf(ToolManager::class);
    });

    it('supports Linux nodes independently of roles and identifies itself as VP', function (
        Node $node,
        bool $supported,
    ): void {
        [$manager] = vp_tool_manager([]);

        expect($manager->name())
            ->toBe(ToolManagerName::Vp)
            ->and($manager->supportsNode($node))
            ->toBe($supported);
    })->with([
        'active app-dev' => [vp_tool_node('linux', [['app-dev', 'active']]), true],
        'provisioning app-prod' => [vp_tool_node('linux', [['app-prod', 'provisioning']]), true],
        'failed app role' => [vp_tool_node('linux', [['app-dev', 'failed']]), true],
        'removing app role' => [vp_tool_node('linux', [['app-prod', 'removing']]), true],
        'gateway only' => [vp_tool_node('linux', [['gateway', 'active']]), true],
        'roleless' => [vp_tool_node('linux', []), true],
        'macOS roleless' => [vp_tool_node('macos', []), true],
        'macOS with an app role' => [vp_tool_node('macos', [['app-dev', 'active']]), true],
        'non-linux app role' => [vp_tool_node('darwin', [['app-dev', 'active']]), false],
    ]);

    it('does not load roles when checking the platform boundary', function (): void {
        [$manager] = vp_tool_manager([]);
        $node = Node::query()->create([
            'name' => 'vp-macos-node',
            'status' => 'active',
            'platform' => 'darwin',
            'public_ssh_host' => '127.0.0.1',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.10.10',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $node = Node::query()->whereKey($node->id)->sole();

        expect($node->relationLoaded('roles'))->toBeFalse();
        expect($manager->supportsNode($node))->toBeFalse();
        expect($node->relationLoaded('roles'))->toBeFalse();

        $mac = Node::query()->create([
            'name' => 'vp-real-macos-node',
            'status' => 'active',
            'platform' => 'macos',
            'public_ssh_host' => '127.0.0.2',
            'user' => 'mini',
            'wireguard_ip' => '10.44.10.11',
        ]);
        $mac->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $mac = Node::query()->whereKey($mac->id)->sole();

        expect($mac->relationLoaded('roles'))->toBeFalse();
        expect($manager->supportsNode($mac))->toBeTrue();
        expect($mac->relationLoaded('roles'))->toBeFalse();
    });

    it('accepts only strict VP package coordinates', function (string $package, bool $valid): void {
        [$manager] = vp_tool_manager([]);

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple package' => ['typescript', true],
        'scoped package' => ['@openai/codex', true],
        'hyphenated scope' => ['@anthropic-ai/claude-code', true],
        'tilde and underscore' => ['package_name~beta', true],
        'empty' => ['', false],
        'uppercase' => ['TypeScript', false],
        'whitespace' => ['type script', false],
        'newline injection' => ["typescript\nwhoami", false],
        'version tag' => ['typescript@latest', false],
        'version range' => ['typescript^5.0.0', false],
        'url' => ['https://registry.npmjs.org/typescript', false],
        'file' => ['file:../typescript', false],
        'alias' => ['npm:typescript', false],
        'path traversal' => ['../package', false],
        'option syntax' => ['-g', false],
        'missing scoped name' => ['@openai/', false],
        'missing scope sigil' => ['openai/codex', false],
        'double slash' => ['@openai//codex', false],
        'oversized' => [str_repeat('a', times: 215), false],
    ]);

    it('skips cache-only vite-plus homes and lets inventory return Complete', function (string $platform): void {
        [$manager, $ssh] = vp_tool_manager([vp_result("/home/orbit/.local/share/vite-plus/bin/vp\n")]);
        $node = vp_tool_node($platform, []);
        $manager->existingBinary($node);

        [$result, $binary] = vp_tool_run_scope_fixture($ssh->commands[0]->input ?? '');

        expect($result->exitCode)->toBe(0, $result->stderr);
        expect(trim($result->stdout))->toBe($binary);

        $inventorySsh = new ToolManagerFakeSshExecutor([$result, vp_result("[]\n")]);
        $runner = new RemoteToolCommandRunner(
            ssh: $inventorySsh,
            keys: vp_tool_keys(),
            knownHosts: vp_tool_known_hosts(),
        );
        $versions = new SemverVersionNormalizer;
        $inspector = new VpInventoryInspector($runner, new VpToolManager($runner, $versions), $versions);

        expect($inspector->inspect($node)->scanState)->toBe(ToolInventoryScanState::Complete);
        expect($inventorySsh->arguments()[1])->toBe(['env', 'VP_HOME='.dirname($binary, 2), $binary, 'list', '-g', '--json']);
    })->with(['linux', 'macos']);

    it('keeps genuine vite-plus scope conflicts at exit 43', function (string $platform, string $fixture): void {
        [$manager, $ssh] = vp_tool_manager([vp_result("/home/orbit/.local/share/vite-plus/bin/vp\n")]);
        $manager->existingBinary(vp_tool_node($platform, []));

        [$result] = vp_tool_run_scope_fixture($ssh->commands[0]->input ?? '', $fixture);

        expect($result->exitCode)->toBe(43, $result->stderr);
    })->with(['linux', 'macos'])->with([
        'symlinked home' => 'symlink',
        'non-directory home' => 'file',
        'wrong home owner' => 'scope-owner',
        'non-executable binary' => 'not-executable',
        'wrong binary owner' => 'binary-owner',
        'dangling binary symlink' => 'binary-symlink',
    ]);

    it('reports cache-only vite-plus homes without a real store as absent', function (string $platform): void {
        [$manager, $ssh] = vp_tool_manager([vp_result("/home/orbit/.local/share/vite-plus/bin/vp\n")]);
        $manager->existingBinary(vp_tool_node($platform, []));

        [$result] = vp_tool_run_scope_fixture($ssh->commands[0]->input ?? '', 'absent');

        expect($result->exitCode)->toBe(42, $result->stderr);
    })->with(['linux', 'macos']);

    it('materializes the real vite-plus store instead of a cache-only home', function (): void {
        [$manager, $ssh] = vp_tool_manager([vp_result()]);
        $manager->materialize(vp_tool_node('linux', []));

        [$result, $binary, $launcher] = vp_tool_run_scope_fixture($ssh->commands[0]->input ?? '', materialize: true);

        expect($result->exitCode)->toBe(0, $result->stderr);
        expect($launcher)->toContain('exec "'.$binary.'" "$@"');
    });

    it('rejects a wrong-owner cache-only vite-plus home before materializing launchers', function (): void {
        [$manager, $ssh] = vp_tool_manager([vp_result()]);
        $manager->materialize(vp_tool_node('linux', []));

        [$result, , $launcher] = vp_tool_run_scope_fixture($ssh->commands[0]->input ?? '', 'scope-owner', materialize: true);

        expect($result->exitCode)->toBe(1, $result->stderr);
        expect($result->stderr)->toContain('Orbit Vite Plus directory conflict:');
        expect($launcher)->toBe('');
    });

    it('materializes the protected VP scope with fixed bootstrap input', function (): void {
        [$manager, $ssh] = vp_tool_manager([vp_result()]);

        $manager->materialize(vp_tool_node('linux', []));

        expect($ssh->arguments())
            ->toBe([['sudo', 'bash', '-seu', '--', 'orbit']])
            ->and($ssh->commands[0]->input)
            ->toContain('curl -fsSL https://vite.plus | bash')
            ->and($ssh->commands[0]->input)
            ->toContain('/usr/local/bin/vp --version');
    });

    it('rejects an unsupported node before any SSH I/O', function (Closure $operation): void {
        [$manager, $ssh] = vp_tool_manager([]);
        $node = vp_tool_node('darwin', [['app-dev', 'active']]);

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)
                    ->toBe('node')
                    ->and($exception->result)
                    ->toBeNull()
                    ->and($exception->getMessage())
                    ->not->toContain('gateway');
            });
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'manager version' => [
            static fn (VpToolManager $manager, Node $node): string => $manager->managerVersion($node),
        ],
        'candidate version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'typescript',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->installedVersion($node, 'typescript'),
        ],
        'install' => [static function (VpToolManager $manager, Node $node): void {
            $manager->install($node, 'typescript');
        }],
        'update' => [static function (VpToolManager $manager, Node $node): void {
            $manager->update($node, 'typescript');
        }],
        'removal plan' => [
            static fn (VpToolManager $manager, Node $node) => $manager->planRemoval($node, 'typescript'),
        ],
        'remove' => [static function (VpToolManager $manager, Node $node): void {
            $manager->remove($node, 'typescript');
        }],
    ]);

    it('rejects an invalid package before any SSH I/O', function (Closure $operation): void {
        [$manager, $ssh] = vp_tool_manager([]);
        $node = vp_tool_node();

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)
                    ->toBe('package')
                    ->and($exception->result)
                    ->toBeNull()
                    ->and($exception->getMessage())
                    ->not->toContain('@openai/');
            });
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'candidate version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                '@openai/',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (VpToolManager $manager, Node $node): ?string => $manager->installedVersion($node, '@openai/'),
        ],
        'install' => [static function (VpToolManager $manager, Node $node): void {
            $manager->install($node, '@openai/');
        }],
        'update' => [static function (VpToolManager $manager, Node $node): void {
            $manager->update($node, '@openai/');
        }],
        'removal plan' => [static fn (VpToolManager $manager, Node $node) => $manager->planRemoval($node, '@openai/')],
        'remove' => [static function (VpToolManager $manager, Node $node): void {
            $manager->remove($node, '@openai/');
        }],
    ]);

    it('uses the approved fixed VP argv through the complete lifecycle', function (): void {
        [$manager, $ssh] = vp_tool_manager([
            vp_result("2.4.1\nextra line ignored\n"),
            vp_result('"5.8.2"'."\n"),
            vp_result('[{"name":"typescript","version":"5.7.3"}]'."\n"),
            vp_result(),
            vp_result(),
            vp_result('dry run text that is ignored'),
            vp_result(),
        ]);
        $node = vp_tool_node();

        $managerVersion = $manager->managerVersion($node);
        $candidateVersion = $manager->candidateVersion($node, 'typescript', ToolOperation::Install);
        $installedVersion = $manager->installedVersion($node, 'typescript');
        $manager->install($node, 'typescript');
        $manager->update($node, 'typescript');
        $removalPlan = $manager->planRemoval($node, 'typescript');
        $manager->remove($node, 'typescript');

        expect($managerVersion)->toBe('2.4.1');
        expect($candidateVersion)->toBe('5.8.2');
        expect($installedVersion)->toBe('5.7.3');
        expect($removalPlan->packages)->toBe(['typescript']);
        expect($removalPlan->removesOnly('typescript'))->toBeTrue();
        expect($ssh->arguments())->toBe([
            ['/usr/local/bin/vp', '--version'],
            ['/usr/local/bin/vp', 'info', 'typescript', 'version', '--json'],
            ['/usr/local/bin/vp', 'list', '-g', 'typescript', '--json'],
            [
                '/usr/local/bin/vp',
                'install',
                '-g',
                'typescript',
                '--node',
                'lts',
            ],
            [
                '/usr/local/bin/vp',
                'update',
                '-g',
                'typescript',
                '--reinstall-node-mismatch',
            ],
            ['/usr/local/bin/vp', 'remove', '-g', '--dry-run', 'typescript'],
            ['/usr/local/bin/vp', 'remove', '-g', 'typescript'],
        ]);
    });

    it('delegates version normalization to the semver normalizer', function (): void {
        [$manager] = vp_tool_manager([]);

        expect($manager->normalizeVersion('v1.2'))
            ->toBe('1.2.0')
            ->and($manager->normalizeVersion('not-a-version'))
            ->toBeNull();
    });

    it('returns null when the installed package list is empty', function (): void {
        [$manager, $ssh] = vp_tool_manager([
            vp_result("[]\n"),
        ]);

        $version = $manager->installedVersion(vp_tool_node(), 'typescript');

        expect($version)->toBeNull();
        expect($ssh->arguments())->toBe([
            ['/usr/local/bin/vp', 'list', '-g', 'typescript', '--json'],
        ]);
    });

    it('rejects an empty top-level object for installed packages', function (): void {
        [$manager] = vp_tool_manager([
            vp_result("{}\n"),
        ]);

        expect(fn () => $manager->installedVersion(vp_tool_node(), 'typescript'))
            ->toThrow(ToolManagerException::class, 'malformed');
    });

    it('rejects a numeric-key top-level object for installed packages', function (): void {
        [$manager] = vp_tool_manager([
            vp_result('{"0":{"name":"typescript","version":"5.7.3"}}'."\n"),
        ]);

        expect(fn () => $manager->installedVersion(vp_tool_node(), 'typescript'))
            ->toThrow(ToolManagerException::class, 'malformed');
    });

    it('returns null when the installed package list contains only substring matches', function (): void {
        [$manager] = vp_tool_manager([
            vp_result('[{"name":"typescript-eslint","version":"8.0.0"}]'),
        ]);

        expect($manager->installedVersion(vp_tool_node(), 'typescript'))->toBeNull();
    });

    it('fails closed on an invalid manager-version result', function (CommandResult $result, string $step): void {
        [$manager] = vp_tool_manager([$result]);

        expect(fn () => $manager->managerVersion(vp_tool_node()))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [vp_result('secret stdout', exitCode: 11, stderr: 'secret stderr'), 'manager-version'],
        'truncated' => [vp_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'missing' => [vp_result(), 'manager-version'],
        'empty first line' => [vp_result("\n2.4.1\n"), 'manager-version'],
        'control bearing' => [vp_result("2.4.1\0hidden\n"), 'manager-version'],
        'oversized' => [vp_result(str_repeat('1', times: 256)."\n"), 'manager-version'],
    ]);

    it('fails closed on invalid candidate-version JSON output', function (CommandResult $result, string $step): void {
        [$manager] = vp_tool_manager([$result]);

        expect(fn () => $manager->candidateVersion(vp_tool_node(), 'typescript', ToolOperation::Install))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [vp_result('secret stdout', exitCode: 12, stderr: 'secret stderr'), 'candidate-version'],
        'truncated' => [vp_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'empty string' => [vp_result('""'), 'candidate-version'],
        'malformed json' => [vp_result('{"version":"5.0.0"}'), 'candidate-version'],
        'non-string' => [vp_result('["5.0.0"]'), 'candidate-version'],
        'duplicate documents' => [vp_result("\"5.0.0\"\n\"6.0.0\""), 'candidate-version'],
        'control bearing' => [vp_result('"5.0.0\\u0000hidden"'), 'candidate-version'],
        'oversized' => [vp_result('"'.str_repeat('1', times: 256).'"'), 'candidate-version'],
    ]);

    it('fails closed on invalid installed-version JSON output', function (CommandResult $result, string $step): void {
        [$manager] = vp_tool_manager([$result]);

        expect(fn () => $manager->installedVersion(vp_tool_node(), 'typescript'))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [vp_result('secret stdout', exitCode: 13, stderr: 'secret stderr'), 'installed-version'],
        'truncated' => [vp_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'malformed json' => [vp_result('{"name":"typescript"}'), 'installed-version'],
        'non-array' => [vp_result('"typescript"'), 'installed-version'],
        'malformed entry type' => [vp_result('[1]'), 'installed-version'],
        'missing version' => [vp_result('[{"name":"typescript"}]'), 'installed-version'],
        'duplicate exact entries' => [
            vp_result('[{"name":"typescript","version":"5.7.3"},{"name":"typescript","version":"5.8.0"}]'),
            'installed-version',
        ],
        'unsafe version' => [vp_result('[{"name":"typescript","version":"5.7.3\u0000hidden"}]'), 'installed-version'],
    ]);

    it('fails closed and sanitizes failed mutations', function (
        Closure $operation,
        array $arguments,
        string $step,
    ): void {
        $stdoutSentinel = 'secret mutation stdout';
        $stderrSentinel = 'secret mutation stderr';
        [$manager, $ssh] = vp_tool_manager([
            vp_result($stdoutSentinel, exitCode: 14, stderr: $stderrSentinel),
        ]);

        expect(fn () => $operation($manager, vp_tool_node()))
            ->toThrow(function (ToolManagerException $exception) use ($stdoutSentinel, $stderrSentinel, $step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty()
                    ->and($exception->getMessage())
                    ->not->toContain($stdoutSentinel, $stderrSentinel);
            });
        expect($ssh->arguments())->toBe([$arguments]);
    })->with([
        'install' => [
            static function (VpToolManager $manager, Node $node): void {
                $manager->install($node, 'typescript');
            },
            [
                '/usr/local/bin/vp',
                'install',
                '-g',
                'typescript',
                '--node',
                'lts',
            ],
            'install',
        ],
        'update' => [
            static function (VpToolManager $manager, Node $node): void {
                $manager->update($node, 'typescript');
            },
            [
                '/usr/local/bin/vp',
                'update',
                '-g',
                'typescript',
                '--reinstall-node-mismatch',
            ],
            'update',
        ],
        'removal plan' => [
            static fn (VpToolManager $manager, Node $node) => $manager->planRemoval($node, 'typescript'),
            ['/usr/local/bin/vp', 'remove', '-g', '--dry-run', 'typescript'],
            'removal-plan',
        ],
        'remove' => [
            static function (VpToolManager $manager, Node $node): void {
                $manager->remove($node, 'typescript');
            },
            ['/usr/local/bin/vp', 'remove', '-g', 'typescript'],
            'remove',
        ],
    ]);

    it('remove executes exactly one VP removal command without replanning', function (): void {
        [$manager, $ssh] = vp_tool_manager([vp_result()]);

        $manager->remove(vp_tool_node(), 'typescript');

        expect($ssh->arguments())->toBe([
            ['/usr/local/bin/vp', 'remove', '-g', 'typescript'],
        ]);
    });

    it('verifies the enrolled macOS Vite+ scope without installing a second one', function (): void {
        [$manager, $ssh] = vp_tool_manager([
            vp_result("/Users/mini/.vite-plus/bin/vp\n"),
        ]);

        $manager->materialize(vp_tool_node('macos', [], 'mini'));

        $program = $ssh->commands[0]->input;
        expect($ssh->arguments())->toBe([['/bin/bash', '-su', '--', 'mini']])
            ->and($ssh->connections[0]->user)->toBe('mini')
            ->and($program)->toContain('/usr/bin/dscacheutil')
            ->and($program)->toContain('/.vite-plus')
            ->and($program)->toContain('/.local/share/vite-plus')
            ->and($program)->not->toContain('getent')
            ->and($program)->not->toContain('stat -c')
            ->and($program)->not->toContain('/home/')
            ->and($program)->not->toContain('systemd')
            ->and($program)->not->toContain('curl')
            ->and($program)->not->toContain('/usr/local/bin')
            ->and($program)->not->toContain('/opt/orbit');

        $script = tempnam(sys_get_temp_dir(), 'orbit-mac-vp-');
        file_put_contents($script, $program);

        try {
            exec('bash -n '.escapeshellarg($script).' 2>&1', $syntaxOutput, $syntaxStatus);
            expect($syntaxStatus)->toBe(0);
        } finally {
            unlink($script);
        }
    });

    it('reports a missing or conflicting macOS Vite+ scope without raw output', function (int $exitCode, string $step): void {
        [$manager] = vp_tool_manager([
            vp_result('secret scope', exitCode: $exitCode, stderr: 'secret stat'),
        ]);

        expect(fn () => $manager->materialize(vp_tool_node('macos', [], 'mini')))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)->toBe($step)
                    ->and($exception->result?->stdout)->toBeEmpty()
                    ->and($exception->result?->stderr)->toBeEmpty()
                    ->and($exception->getMessage())->not->toContain('secret');
            });
    })->with([
        'absent' => [42, 'manager-absent'],
        'conflicting store' => [43, 'manager-conflict'],
        'unreadable probe' => [1, 'manager-probe'],
    ]);

    it('uses the enrolled macOS Vite+ binary for one global package', function (): void {
        $binary = '/Users/mini/.local/share/vite-plus/bin/vp';
        $scope = vp_result($binary."\n");
        $list = (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/vp-list-g.json');
        [$manager, $ssh] = vp_tool_manager([
            $scope,
            vp_result("vp v0.2.6\n"),
            $scope,
            vp_result('"0.150.0"'."\n"),
            $scope,
            vp_result($list),
            $scope,
            vp_result($list),
            $scope,
            vp_result(),
            $scope,
            vp_result(),
            $scope,
            vp_result('dry run'),
            $scope,
            vp_result(),
        ]);
        $node = vp_tool_node('macos', [], 'mini');

        expect($manager->managerVersion($node))->toBe('vp v0.2.6')
            ->and($manager->candidateVersion($node, '@openai/codex', ToolOperation::Install))->toBe('0.150.0')
            ->and($manager->installedVersion($node, '@openai/codex'))->toBe('0.150.0')
            ->and($manager->installedVersion($node, 'pnpm'))->toBe('10.15.1');
        $manager->install($node, '@openai/codex');
        $manager->update($node, '@openai/codex');
        expect($manager->planRemoval($node, '@openai/codex')->packages)->toBe(['@openai/codex']);
        $manager->remove($node, '@openai/codex');

        expect($ssh->arguments())->toBe([
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, '--version'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'info', '@openai/codex', 'version', '--json'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'list', '-g', '@openai/codex', '--json'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'list', '-g', 'pnpm', '--json'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'install', '-g', '@openai/codex', '--node', 'lts'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'update', '-g', '@openai/codex', '--reinstall-node-mismatch'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'remove', '-g', '--dry-run', '@openai/codex'],
            ['/bin/bash', '-su', '--', 'mini'],
            [$binary, 'remove', '-g', '@openai/codex'],
        ]);
    });

    it('reads scoped package names and versions from Vite+ global list fixtures', function (): void {
        $human = (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/vp-list-g.txt');
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/vp-list-g.json'), true);

        expect($human)->toContain('Package')
            ->and($human)->toContain('Node version')
            ->and($human)->toContain('Binaries')
            ->and($human)->toMatch('/@[a-z0-9._~-]+\/[a-z0-9._~-]+@\d+\.\d+\.\d+/');
        expect($json)->toBeArray();
        $names = array_column($json, 'name');
        $versions = array_column($json, 'version');
        expect($names)->toContain('@openai/codex')
            ->and($names)->toContain('@anthropic-ai/claude-code')
            ->and($versions)->toContain('0.150.0')
            ->and($versions)->toContain('1.0.24');
    });

    it('protects the Vite+ pnpm root without installing it', function (): void {
        [$manager, $ssh] = vp_tool_manager([
            vp_result("/opt/orbit/vite-plus/bin/vp\n"),
            vp_result("[{\"name\":\"pnpm\",\"version\":\"10.15.1\"}]\n"),
        ]);

        expect($manager->inspectForAdoption(vp_tool_node('linux', []), 'pnpm'))
            ->toEqual(new ToolAdoptionFact('10.15.1', ToolInventoryPackage::BLOCK_PROTECTED))
            ->and(json_encode($ssh->arguments()))
            ->not->toContain('install')
            ->not->toContain('curl');
    });
});

/**
 * @param  list<CommandResult>  $results
 * @return array{VpToolManager, ToolManagerFakeSshExecutor}
 */
function vp_tool_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    $runner = new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: vp_tool_keys(),
        knownHosts: vp_tool_known_hosts(),
    );

    return [
        new VpToolManager(
            commands: $runner,
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

/**
 * @param  list<array{0: 'gateway'|'vpn'|'app-dev'|'app-prod', 1: 'provisioning'|'active'|'failed'|'removing'}>  $roles
 */
function vp_tool_node(string $platform = 'linux', array $roles = [['app-dev', 'active']], string $user = 'orbit'): Node
{
    $node = new Node([
        'name' => 'vp-tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => '10.8.0.43',
    ]);

    $node->setRelation(
        'roles',
        collect(array_map(
            static fn (array $role): NodeRole => new NodeRole([
                'role' => RoleName::from($role[0]),
                'status' => LifecycleStatus::from($role[1]),
            ]),
            $roles,
        )),
    );

    return $node;
}

function vp_result(
    string $stdout = '',
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult(
        exitCode: $exitCode,
        stdout: $stdout,
        stderr: $stderr,
        durationMs: 10,
        truncated: $truncated,
    );
}

/**
 * Runs the emitted Bash against disposable homes, with account lookup and ownership stubs.
 * Materialization publishes launchers only inside the fixture, with no sudo or network access.
 *
 * @return array{CommandResult, string, string}
 */
function vp_tool_run_scope_fixture(
    string $program,
    string $fixture = 'cache-only',
    bool $materialize = false,
): array {
    $root = sys_get_temp_dir().'/orbit-vp-cache-'.bin2hex(random_bytes(4));
    $home = $root.'/home';
    $orbit = $root.'/orbit';
    $scope = $home.'/.vite-plus';
    $store = $home.'/.local/share/vite-plus';
    $account = trim((string) shell_exec('id -un'));
    mkdir($scope.'/package_manager/npm/cache', 0755, true);
    mkdir($orbit.'/vite-plus/package_manager/npm/cache', 0755, true);
    mkdir($root.'/launchers', 0755, true);
    if ($fixture !== 'absent') {
        mkdir($store.'/bin', 0755, true);
        foreach (['vp', 'node', 'pnpm', 'npm', 'npx'] as $binary) {
            file_put_contents($store.'/bin/'.$binary, "#!/bin/sh\nexit 0\n");
            chmod($store.'/bin/'.$binary, 0755);
        }
    }
    if ($fixture === 'symlink' || $fixture === 'file') {
        rename($scope, $home.'/cache');
        if ($fixture === 'symlink') {
            symlink($home.'/cache', $scope);
        } else {
            file_put_contents($scope, 'not a directory');
        }
    }
    if (in_array($fixture, ['not-executable', 'binary-owner', 'binary-symlink'], true)) {
        mkdir($scope.'/bin', 0755, true);
        if ($fixture === 'binary-symlink') {
            symlink($root.'/missing', $scope.'/bin/vp');
        } else {
            file_put_contents($scope.'/bin/vp', "#!/bin/sh\nexit 0\n");
            chmod($scope.'/bin/vp', $fixture === 'not-executable' ? 0644 : 0755);
        }
    }
    $wrongOwnerPath = match ($fixture) {
        'scope-owner' => $scope,
        'binary-owner' => $scope.'/bin/vp',
        default => $root.'/unused',
    };
    $stubs = [
        'getent' => "#!/bin/sh\nprintf '%s\\n' '{$account}:x:1:1::{$home}:/bin/sh'\n",
        'dscacheutil' => "#!/bin/sh\nprintf '%s\\n' 'dir: {$home}'\n",
        'stat' => <<<SH
            #!/bin/sh
            path=
            for path do :; done
            if [ "\$path" = '{$orbit}' ]; then
                printf 'root:root\n'
            elif [ "\$path" = '{$wrongOwnerPath}' ]; then
                printf 'other:other\n'
            elif [ "\$1" = '-f' ]; then
                printf '%s\n' '{$account}'
            else
                exec /usr/bin/stat "\$@"
            fi
            SH,
        'sudo' => "#!/bin/sh\nshift 3\nexec \"\$@\"\n",
        'curl' => "#!/bin/sh\nprintf 'unexpected installer invocation\\n' >&2\nexit 99\n",
    ];
    foreach ($stubs as $name => $contents) {
        file_put_contents($root.'/'.$name, $contents);
        chmod($root.'/'.$name, 0755);
    }
    $program = strtr($program, [
        '/usr/bin/getent' => $root.'/getent',
        'getent passwd' => $root.'/getent passwd',
        '/usr/bin/dscacheutil' => $root.'/dscacheutil',
        '/usr/bin/stat' => $root.'/stat',
        'stat -c' => $root.'/stat -c',
        '/opt/orbit' => $orbit,
        '/usr/local/bin' => $root.'/launchers',
        'chown root:root' => 'true',
        'sudo -u' => $root.'/sudo -u',
        'curl -fsSL' => $root.'/curl -fsSL',
    ]);
    $pipes = [];
    $process = proc_open(
        ['bash', '-seu', '--', $account],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    try {
        fwrite($pipes[0], $program);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $launcher = $materialize && is_file($root.'/launchers/vp')
            ? (string) file_get_contents($root.'/launchers/vp')
            : '';

        return [vp_result((string) $stdout, $status, (string) $stderr), $store.'/bin/vp', $launcher];
    } finally {
        exec('rm -rf -- '.escapeshellarg($root));
    }
}

function vp_tool_keys(): SshKeyProvider
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

function vp_tool_known_hosts(): KnownHostsStore
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
