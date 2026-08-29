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
use Tests\Support\ToolManagerFakeSshExecutor;

/** @mago-expect lint:halstead The VP matrix keeps package grammar, node gating, JSON parsing, and fixed argv observable. */
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

    it('supports only Linux nodes with active or provisioning app roles and identifies itself as VP', function (
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
        'failed app role' => [vp_tool_node('linux', [['app-dev', 'failed']]), false],
        'removing app role' => [vp_tool_node('linux', [['app-prod', 'removing']]), false],
        'gateway only' => [vp_tool_node('linux', [['gateway', 'active']]), false],
        'roleless' => [vp_tool_node('linux', []), false],
        'non-linux app role' => [vp_tool_node('darwin', [['app-dev', 'active']]), false],
    ]);

    it('loads roles before rejecting a persisted non-linux node', function (): void {
        [$manager] = vp_tool_manager([]);
        $node = Node::query()->create([
            'name' => 'vp-macos-node',
            'status' => 'active',
            'platform' => 'darwin',
            'public_ssh_host' => '127.0.0.1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.10.10',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $node = Node::query()->whereKey($node->id)->sole();

        expect($node->relationLoaded('roles'))->toBeFalse();
        expect($manager->supportsNode($node))->toBeFalse();
        expect($node->relationLoaded('roles'))->toBeTrue();
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

    it('rejects an unsupported node before any SSH I/O', function (Closure $operation): void {
        [$manager, $ssh] = vp_tool_manager([]);
        $node = vp_tool_node('linux', [['gateway', 'active']]);

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
        'control bearing' => [vp_result("\"5.0.0\\u0000hidden\""), 'candidate-version'],
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
 * @param list<CommandResult> $results
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
 * @param list<array{0: 'gateway'|'vpn'|'app-dev'|'app-prod', 1: 'provisioning'|'active'|'failed'|'removing'}> $roles
 */
function vp_tool_node(string $platform = 'linux', array $roles = [['app-dev', 'active']]): Node
{
    $node = new Node([
        'name' => 'vp-tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_address' => '10.8.0.43',
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
    return new class implements SshKeyProvider {
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
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
