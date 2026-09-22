<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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

    it('resolves nondefault managed homes and owns the complete VP environment', function (): void {
        [$manager, $ssh] = vp_tool_manager([vp_result()]);
        $node = vp_tool_node();
        $node->user = 'nckrtl';

        $manager->materialize($node);

        $script = $ssh->commands[0]->input ?? '';
        $syntax = new Process(['bash', '-n']);
        $syntax->setInput($script);
        $syntax->run();
        expect($ssh->arguments())->toBe([['sudo', 'bash', '-seu', '--', 'nckrtl']])
            ->and($script)->toContain(
                'getent passwd -- "$managed_user"',
                'managed_home=$(printf',
                'for candidate in /opt/orbit/vite-plus "$managed_home/.vite-plus" "$managed_home/.local/share/vite-plus"',
                'env setup',
                'env on',
                'env install lts',
                'env default lts',
                'install -g --node lts pnpm',
                'stat -c \'%U:%G\' "$launcher"',
                'stat -c \'%a\' "$launcher"',
                'cmp -s "$launcher" "$candidate"',
                'rollback_vp_runtime()',
            )->and($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput());
    });

    it('propagates a failed VP installer download', function (): void {
        $script = vp_materialization_script();
        $installer = collect(preg_split('/\R/', $script))->first(static fn (string $line): bool => str_contains($line, 'https://vite.plus'));
        expect($installer)->toBeString()->toContain('bash -o pipefail -c');
        $failureCommand = str_replace(
            ['sudo -u "$managed_user" -H ', 'curl -fsSL https://vite.plus'],
            ['', 'false'],
            trim($installer),
        );
        $process = Process::fromShellCommandline($failureCommand);
        $process->run();

        expect($process->isSuccessful())->toBeFalse();
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
function vp_tool_node(string $platform = 'linux', array $roles = [['app-dev', 'active']]): Node
{
    $node = new Node([
        'name' => 'vp-tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
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

it('adopts an existing Vite Plus installation without running environment mutations', function (): void {
    $script =
        vp_materialization_script();
    $fragment = vp_runtime_fragment($script);
    $root = sys_get_temp_dir().'/orbit-vite-plus-adoption-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/.vite-plus/bin", 0o755, true);
    $filesystem->put("{$root}/.vite-plus/bin/vp", "#!/bin/sh\nprintf '%s\n' \"\$*\" >> \"\$VP_LOG\"\n");
    $filesystem->put("{$root}/.vite-plus/bin/pnpm", "#!/bin/sh\nexit 0\n");
    chmod("{$root}/.vite-plus/bin/vp", 0o755);
    chmod("{$root}/.vite-plus/bin/pnpm", 0o755);
    $log = "{$root}/vp.log";

    try {
        $process = new Process(['bash', '-seu']);
        $process->setEnv(['VP_LOG' => $log]);
        $process->setInput("managed_user=$(id -un)\nmanaged_group=$(id -gn)\nmanaged_home={$root}\n{$fragment}");
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())->and(is_file($log))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

it('executes the default installer into the XDG Vite Plus home', function (): void {
    $script = vp_runtime_fragment(
        vp_materialization_script(),
    );
    $root = sys_get_temp_dir().'/orbit-vite-plus-xdg-'.Str::uuid();
    new Filesystem()->makeDirectory($root, 0o755, true);
    $script = str_replace('sudo -u "$managed_user" -H ', '', $script);
    $script = str_replace(
        "env -u VP_HOME bash -o pipefail -c 'curl -fsSL https://vite.plus | bash'",
        "mkdir -p \"\$managed_home/.local/share/vite-plus/bin\"; printf '#!/bin/sh\\nexit 0\\n' > \"\$managed_home/.local/share/vite-plus/bin/vp\"; printf '#!/bin/sh\\nexit 0\\n' > \"\$managed_home/.local/share/vite-plus/bin/pnpm\"; chmod 755 \"\$managed_home/.local/share/vite-plus/bin/vp\" \"\$managed_home/.local/share/vite-plus/bin/pnpm\"",
        $script,
    );

    try {
        $process = new Process(['bash', '-seu']);
        $process->setInput("managed_user=$(id -un)\nmanaged_group=$(id -gn)\nmanaged_home={$root}\n{$script}");
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput())
            ->and("{$root}/.local/share/vite-plus/bin/vp")
            ->toBeFile()
            ->and("{$root}/.vite-plus")
            ->not->toBeDirectory();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('rejects Vite Plus home conflicts before adoption or installation', function (string $type): void {
    $script =
        vp_materialization_script();
    $fragment = vp_runtime_fragment($script);
    $root = sys_get_temp_dir().'/orbit-vite-plus-conflict-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($root, 0o755, true);
    $vitePlus = "{$root}/.vite-plus";
    $type === 'symlink' ? symlink('/tmp/foreign-vite-plus', $vitePlus) : $filesystem->put($vitePlus, "foreign\n");

    try {
        $process = new Process(['bash', '-seu']);
        $process->setInput("managed_user=$(id -un)\nmanaged_group=$(id -gn)\nmanaged_home={$root}\n{$fragment}");
        $process->run();

        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getErrorOutput())
            ->toContain('Orbit Vite Plus directory conflict:');
    } finally {
        $filesystem->deleteDirectory($root);
    }
})->with(['symlink', 'file']);

it('rejects foreign launchers before publishing stable entry points', function (): void {
    $script =
        vp_materialization_script();
    $harness = vp_runtime_harness($script, foreignLauncher: 'npm');

    try {
        expect($harness['process']->isSuccessful())
            ->toBeFalse()
            ->and($harness['process']->getErrorOutput())
            ->toContain("Orbit Vite Plus launcher conflict: {$harness['stableDirectory']}/npm")
            ->and(file_get_contents("{$harness['stableDirectory']}/npm"))
            ->toBe("foreign\n");

        foreach (['vp', 'node', 'pnpm', 'npx'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }

        expect($harness['candidateDirectories'])->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('accepts existing Orbit launchers for the old Vite Plus home', function (): void {
    $script =
        vp_materialization_script();
    $harness = vp_runtime_harness($script, legacyLaunchers: true);

    try {
        expect($harness['process']->isSuccessful())->toBeTrue($harness['process']->getErrorOutput());
        expect($harness['versionChecks'])->toBe([
            "-u {$harness['owner']} -H {$harness['stableDirectory']}/vp --version => 0",
            "-u {$harness['owner']} -H {$harness['stableDirectory']}/node --version => 0",
            "-u {$harness['owner']} -H {$harness['stableDirectory']}/pnpm --version => 0",
            "-u {$harness['owner']} -H {$harness['stableDirectory']}/npm --version => 0",
            "-u {$harness['owner']} -H {$harness['stableDirectory']}/npx --version => 0",
        ]);

        foreach (['vp', 'node', 'pnpm', 'npm', 'npx'] as $binary) {
            expect(file_get_contents("{$harness['stableDirectory']}/{$binary}"))
                ->toBe(
                    "#!/bin/sh\nexport VP_HOME=/opt/orbit/vite-plus\nexec \"{$harness['sourceDirectory']}/{$binary}\" \"\$@\"\n",
                );
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('preserves exact launchers while rolling back new entry points after verification fails', function (): void {
    $script =
        vp_materialization_script();
    $harness = vp_runtime_harness(
        $script,
        failingRuntime: 'npx',
        failingRuntimeExitCode: 23,
        exactLauncher: 'vp',
    );

    try {
        expect($harness['process']->isSuccessful())
            ->toBeFalse()
            ->and($harness['process']->getExitCode())
            ->toBe(23)
            ->and($harness['versionChecks'])
            ->toBe([
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/vp --version => 0",
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/node --version => 0",
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/pnpm --version => 0",
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/npm --version => 0",
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/npx --version => 23",
            ])
            ->and(file_get_contents("{$harness['stableDirectory']}/vp"))
            ->toBe($harness['exactLauncherContents'])
            ->and(file_get_contents("{$harness['stableDirectory']}/unrelated"))
            ->toBe("unrelated\n")
            ->and($harness['candidateDirectories'])
            ->toBeEmpty();

        foreach (['node', 'pnpm', 'npm', 'npx'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('distinguishes an earlier version failure from the intended npx rollback failure', function (): void {
    $script =
        vp_materialization_script();
    $harness = vp_runtime_harness(
        $script,
        failingRuntime: 'node',
        failingRuntimeExitCode: 19,
        exactLauncher: 'vp',
    );

    try {
        expect($harness['process']->getExitCode())
            ->toBe(19)
            ->and($harness['versionChecks'])
            ->toBe([
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/vp --version => 0",
                "-u {$harness['owner']} -H {$harness['stableDirectory']}/node --version => 19",
            ])
            ->and(file_get_contents("{$harness['stableDirectory']}/vp"))
            ->toBe($harness['exactLauncherContents'])
            ->and($harness['candidateDirectories'])
            ->toBeEmpty();

        foreach (['node', 'pnpm', 'npm', 'npx'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('rejects privileged version checks outside the fixture contract', function (string $unexpectedInvocation): void {
    $script =
        vp_materialization_script();
    $harness = vp_runtime_harness($script, unexpectedPrivilegedInvocation: $unexpectedInvocation);
    $unexpectedCommand = $unexpectedInvocation === 'shape'
        ? "-u {$harness['owner']} {$harness['stableDirectory']}/vp --version"
        : "-u {$harness['owner']} -H {$harness['root']}/nonfixture --version";

    try {
        expect($harness['process']->getExitCode())
            ->toBe(125)
            ->and($harness['process']->getErrorOutput())
            ->toContain("Rejected JavaScript runtime fixture command ({$unexpectedInvocation}): {$unexpectedCommand}")
            ->and($harness['versionChecks'])
            ->toBeEmpty()
            ->and($harness['nonFixtureExecuted'])
            ->toBeFalse()
            ->and($harness['candidateDirectories'])
            ->toBeEmpty();

        foreach (['vp', 'node', 'pnpm', 'npm', 'npx'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
})->with([
    'unexpected command shape' => 'shape',
    'nonfixture executable target' => 'target',
]);

/**
 * @return array{root: string, sourceDirectory: string, stableDirectory: string, owner: string, exactLauncherContents: string, versionChecks: list<string>, nonFixtureExecuted: bool, candidateDirectories: list<string>, process: Process}
 */
function vp_runtime_harness(
    string $script,
    ?string $foreignLauncher = null,
    ?string $failingRuntime = null,
    int $failingRuntimeExitCode = 1,
    ?string $exactLauncher = null,
    bool $legacyLaunchers = false,
    ?string $unexpectedPrivilegedInvocation = null,
): array {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-vp-runtime-'.Str::random(16);
    $sourceDirectory = "{$root}/source";
    $stableDirectory = "{$root}/stable";
    $filesystem->makeDirectory($sourceDirectory, 0o700, recursive: true);
    $filesystem->makeDirectory($stableDirectory, 0o700, recursive: true);
    $filesystem->put("{$stableDirectory}/unrelated", "unrelated\n");

    foreach (['vp', 'node', 'pnpm', 'npm', 'npx'] as $binary) {
        $exitCode = $binary === $failingRuntime ? $failingRuntimeExitCode : 0;
        $filesystem->put("{$sourceDirectory}/{$binary}", "#!/bin/sh\nexit {$exitCode}\n");
        chmod(filename: "{$sourceDirectory}/{$binary}", permissions: 0o755);
    }

    $exactLauncherContents = '';

    if ($exactLauncher !== null) {
        $exactLauncherContents = "#!/bin/sh\nexport VP_HOME=/opt/orbit/vite-plus\nexec \"{$sourceDirectory}/{$exactLauncher}\" \"\$@\"\n";
        $filesystem->put("{$stableDirectory}/{$exactLauncher}", $exactLauncherContents);
        chmod(filename: "{$stableDirectory}/{$exactLauncher}", permissions: 0o755);
    }

    if ($legacyLaunchers) {
        foreach (['vp', 'node', 'pnpm', 'npm', 'npx'] as $binary) {
            $filesystem->put(
                "{$stableDirectory}/{$binary}",
                "#!/bin/sh\nexport VP_HOME=/opt/orbit/vite-plus\nexec \"{$sourceDirectory}/{$binary}\" \"\$@\"\n",
            );
            chmod(filename: "{$stableDirectory}/{$binary}", permissions: 0o755);
        }
    }

    if ($foreignLauncher !== null) {
        $filesystem->put("{$stableDirectory}/{$foreignLauncher}", "foreign\n");
        chmod(filename: "{$stableDirectory}/{$foreignLauncher}", permissions: 0o755);
    }

    $start = mb_strpos(
        haystack: $script,
        needle: 'launcher_candidates=$(mktemp -d "/usr/local/bin/.orbit-vp-runtime.XXXXXX")',
    );
    $end = is_int($start) ? mb_strpos(haystack: $script, needle: 'trap - EXIT', offset: $start) : false;

    if (! is_int($start) || ! is_int($end)) {
        throw new RuntimeException('Could not isolate the JavaScript runtime publication block.');
    }

    $publicationScript = mb_substr(
        string: $script,
        start: $start,
        length: $end - $start + mb_strlen('trap - EXIT'),
    );
    $owner = posix_getpwuid(fileowner($stableDirectory));
    $group = posix_getgrgid(filegroup($stableDirectory));

    if (! is_array($owner) || ! is_array($group)) {
        throw new RuntimeException('Could not resolve the JavaScript runtime harness owner.');
    }

    $publicationScript = str_replace(
        [
            '$managed_home/.vite-plus/bin',
            '$vp_home/bin',
            '/usr/local/bin',
            "'root:root'",
            'chown root:root "$candidate"',
        ],
        [$sourceDirectory, $sourceDirectory, $stableDirectory, "'{$owner['name']}:{$group['name']}'", 'true'],
        $publicationScript,
    );
    $privilegeFixture = "{$root}/privilege-fixture";
    $versionCheckLog = "{$root}/version-checks.log";
    $privilegeFixtureScript = <<<'SH'
#!/bin/sh
set -u
reject() {
    printf 'Rejected JavaScript runtime fixture command (%s): %s\n' "$1" "$original" >&2
    exit 125
}
original="$*"
[ "$#" -ge 5 ] || reject shape
[ "$1" = -u ] || reject shape
[ "$2" = "__OWNER__" ] || reject shape
[ "$3" = -H ] || reject shape
shift 3
[ "$#" -eq 2 ] || reject shape
target=$1
[ "$2" = --version ] || reject shape
case "$target" in
    __STABLE_DIRECTORY__/vp|__STABLE_DIRECTORY__/node|__STABLE_DIRECTORY__/pnpm|__STABLE_DIRECTORY__/npm|__STABLE_DIRECTORY__/npx) ;;
    *) reject target ;;
esac
[ -x "$target" ] || reject target
if "$target" --version; then
    status=0
else
    status=$?
fi
printf '%s => %s\n' "$original" "$status" >> __VERSION_CHECK_LOG__
exit "$status"
SH;
    $privilegeFixtureScript = str_replace(
        ['__OWNER__', '__STABLE_DIRECTORY__', '__VERSION_CHECK_LOG__'],
        [$owner['name'], $stableDirectory, $versionCheckLog],
        $privilegeFixtureScript,
    );
    $filesystem->put($privilegeFixture, $privilegeFixtureScript);
    chmod(filename: $privilegeFixture, permissions: 0o755);
    $nonFixtureMarker = "{$root}/nonfixture-ran";
    $nonFixtureExecutable = "{$root}/nonfixture";
    $filesystem->put($nonFixtureExecutable, "#!/bin/sh\nprintf 'ran\\n' > {$nonFixtureMarker}\n");
    chmod(filename: $nonFixtureExecutable, permissions: 0o755);

    $publicationScript = match ($unexpectedPrivilegedInvocation) {
        null => $publicationScript,
        'shape' => str_replace(
            "sudo -u \"\$managed_user\" -H {$stableDirectory}/vp --version",
            "sudo -u \"\$managed_user\" {$stableDirectory}/vp --version",
            $publicationScript,
        ),
        'target' => str_replace(
            "sudo -u \"\$managed_user\" -H {$stableDirectory}/vp --version",
            "sudo -u \"\$managed_user\" -H {$nonFixtureExecutable} --version",
            $publicationScript,
        ),
        default => throw new InvalidArgumentException('Unknown unexpected privileged invocation.'),
    };
    $publicationScript = str_replace('sudo -u ', escapeshellarg($privilegeFixture).' -u ', $publicationScript, $adaptedCalls);

    if ($adaptedCalls !== 5 || preg_match('/(^|\s)sudo(\s|$)/m', $publicationScript) === 1) {
        throw new RuntimeException('Could not isolate every JavaScript runtime privileged fixture command.');
    }

    $process = new Process(['bash', '-seu']);
    $process->setInput(
        "managed_user=$(id -un)\nvp_home={$sourceDirectory}\nvp_environment='VP_HOME=/opt/orbit/vite-plus'\nlauncher_environment='export VP_HOME=/opt/orbit/vite-plus'\n{$publicationScript}\n",
    );
    $process->run();

    return [
        'root' => $root,
        'sourceDirectory' => $sourceDirectory,
        'stableDirectory' => $stableDirectory,
        'owner' => $owner['name'],
        'exactLauncherContents' => $exactLauncherContents,
        'versionChecks' => is_file($versionCheckLog) ? file($versionCheckLog, FILE_IGNORE_NEW_LINES) : [],
        'nonFixtureExecuted' => is_file($nonFixtureMarker),
        'candidateDirectories' => glob("{$stableDirectory}/.orbit-vp-runtime.*") ?: [],
        'process' => $process,
    ];
}

function vp_runtime_fragment(string $script): string
{
    $start = mb_strpos($script, 'vp_home=');
    $end = mb_strpos($script, 'launcher_candidates=', $start === false ? 0 : $start);

    if (! is_int($start) || ! is_int($end)) {
        throw new RuntimeException('Could not isolate the Vite Plus runtime block.');
    }

    return str_replace('sudo -u "$managed_user" -H ', '', mb_substr($script, $start, $end - $start));
}

function vp_materialization_script(): string
{
    [$manager, $ssh] = vp_tool_manager([vp_result()]);
    $manager->materialize(vp_tool_node('linux', []));

    return $ssh->commands[0]->input ?? '';
}
