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
use App\Infrastructure\Tools\ComposerDryRunVersionParser;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\ToolManagerFakeSshExecutor;

/** @mago-expect lint:halstead The focused matrix keeps each Composer input, parser, and fixed-command boundary observable. */
describe(ComposerToolManager::class, function (): void {
    it('implements the Composer tool manager adapter', function (): void {
        [$manager] = composer_tool_manager([]);

        expect($manager)->toBeInstanceOf(ToolManager::class);
        expect($manager->name())->toBe(ToolManagerName::Composer);
    });

    it('supports only provisioning or active Linux app nodes', function (
        string $platform,
        LifecycleStatus $nodeStatus,
        ?RoleName $role,
        ?LifecycleStatus $roleStatus,
        bool $supported,
    ): void {
        [$manager] = composer_tool_manager([]);

        expect($manager->supportsNode(composer_tool_node($platform, $nodeStatus, $role, $roleStatus)))
            ->toBe($supported);
    })->with([
        'active app-dev' => ['linux', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Active, true],
        'provisioning app-prod' => [
            'linux',
            LifecycleStatus::Provisioning,
            RoleName::AppProd,
            LifecycleStatus::Provisioning,
            true,
        ],
        'provisioning role on active node' => [
            'linux',
            LifecycleStatus::Active,
            RoleName::AppDev,
            LifecycleStatus::Provisioning,
            true,
        ],
        'gateway role' => ['linux', LifecycleStatus::Active, RoleName::Gateway, LifecycleStatus::Active, false],
        'failed role' => ['linux', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Failed, false],
        'removing role' => ['linux', LifecycleStatus::Active, RoleName::AppProd, LifecycleStatus::Removing, false],
        'non-Linux node' => ['darwin', LifecycleStatus::Active, RoleName::AppDev, LifecycleStatus::Active, false],
        'missing role' => ['linux', LifecycleStatus::Active, null, null, false],
    ]);

    it('accepts only one lowercase Composer package coordinate of at most 255 bytes', function (
        string $package,
        bool $valid,
    ): void {
        [$manager] = composer_tool_manager([]);

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'normal package' => ['laravel/installer', true],
        'single character segments' => ['a/b', true],
        'allowed punctuation' => ['vendor-name/package_name.php', true],
        '255 bytes' => [str_repeat('v', times: 127).'/'.str_repeat('p', times: 127), true],
        'empty' => ['', false],
        'missing package' => ['laravel', false],
        'extra segment' => ['laravel/framework/installer', false],
        'uppercase' => ['Laravel/installer', false],
        'leading punctuation' => ['.laravel/installer', false],
        'trailing punctuation' => ['laravel/installer-', false],
        'leading option' => ['--working-dir/package', false],
        'constraint' => ['laravel/installer:^5.0', false],
        'stability flag' => ['laravel/installer@dev', false],
        'URL' => ['https://example.com/package', false],
        'whitespace' => ['laravel/installer package', false],
        'traversal prefix' => ['../laravel/installer', false],
        'traversal segment' => ['laravel/../installer', false],
        'wildcard' => ['laravel/*', false],
        'oversized' => [str_repeat('v', times: 128).'/'.str_repeat('p', times: 127), false],
    ]);

    it('rejects an unsupported node before remote I/O', function (Closure $operation): void {
        [$manager, $ssh] = composer_tool_manager([]);
        $node = composer_tool_node(role: RoleName::Gateway);

        expect(fn () => $operation($manager, $node))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('node');
                expect($exception->result)->toBeNull();
            });
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'manager version' => [
            static fn (ComposerToolManager $manager, Node $node): string => $manager->managerVersion($node),
        ],
        'candidate version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'laravel/installer',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->installedVersion(
                $node,
                'laravel/installer',
            ),
        ],
        'install' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->install($node, 'laravel/installer'),
        ],
        'update' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->update($node, 'laravel/installer'),
        ],
        'removal plan' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->planRemoval($node, 'laravel/installer'),
        ],
        'remove' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->remove($node, 'laravel/installer'),
        ],
    ]);

    it('rejects an invalid package before remote I/O', function (Closure $operation): void {
        [$manager, $ssh] = composer_tool_manager([]);

        expect(fn () => $operation($manager, composer_tool_node()))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('package');
                expect($exception->result)->toBeNull();
                expect($exception->getMessage())->not->toContain('https://');
            });
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'candidate version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'https://example.com/package',
                ToolOperation::Install,
            ),
        ],
        'installed version' => [
            static fn (ComposerToolManager $manager, Node $node): ?string => $manager->installedVersion(
                $node,
                'https://example.com/package',
            ),
        ],
        'install' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->install(
                $node,
                'https://example.com/package',
            ),
        ],
        'update' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->update(
                $node,
                'https://example.com/package',
            ),
        ],
        'removal plan' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->planRemoval(
                $node,
                'https://example.com/package',
            ),
        ],
        'remove' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->remove(
                $node,
                'https://example.com/package',
            ),
        ],
    ]);

    it('uses fixed private-root argv for the complete lifecycle', function (): void {
        [$manager, $ssh] = composer_tool_manager([
            composer_result("Composer version 2.8.12 2025-09-19 13:41:59\nPHP version 8.5.0\n"),
            composer_result(composer_show(package: 'laravel/installer', version: 'v5.16.0')),
            composer_result(
                stderr: "  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)",
            ),
            composer_result('  - Upgrading laravel/installer (v5.16.0 => v5.17.0)'),
            composer_result(),
            composer_result(),
            composer_result(),
            composer_result(),
        ]);
        $node = composer_tool_node();

        $managerVersion = $manager->managerVersion($node);
        $installedVersion = $manager->installedVersion($node, 'laravel/installer');
        $installCandidate = $manager->candidateVersion($node, 'laravel/installer', ToolOperation::Install);
        $updateCandidate = $manager->candidateVersion($node, 'laravel/installer', ToolOperation::Update);
        $manager->install($node, 'laravel/installer');
        $manager->update($node, 'laravel/installer');
        $removalPlan = $manager->planRemoval($node, 'laravel/installer');
        $manager->remove($node, 'laravel/installer');

        expect($managerVersion)->toBe('Composer version 2.8.12 2025-09-19 13:41:59');
        expect($installedVersion)->toBe('v5.16.0');
        expect($installCandidate)->toBe('v5.17.0');
        expect($updateCandidate)->toBe('v5.17.0');
        expect($removalPlan->packages)->toBe(['laravel/installer']);
        expect($removalPlan->removesOnly('laravel/installer'))->toBeTrue();

        $prefix = ['env', 'COMPOSER_HOME=/opt/orbit/composer', '/usr/bin/composer', 'global'];

        expect($ssh->arguments())->toBe([
            ['/usr/bin/composer', '--version', '--no-ansi'],
            [...$prefix, 'show', '--format=json', '--no-ansi'],
            [
                ...$prefix,
                'require',
                'laravel/installer:*',
                '--dry-run',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
            [
                ...$prefix,
                'update',
                'laravel/installer',
                '--dry-run',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
            [
                ...$prefix,
                'require',
                'laravel/installer:*',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
            [
                ...$prefix,
                'update',
                'laravel/installer',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
            [
                ...$prefix,
                'remove',
                'laravel/installer',
                '--dry-run',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
            [
                ...$prefix,
                'remove',
                'laravel/installer',
                '--no-interaction',
                '--no-ansi',
                '--no-progress',
                '--no-audit',
                '--with-all-dependencies',
            ],
        ]);
        expect($ssh->arguments())
            ->each(
                static fn ($arguments) => $arguments->not->toContain(
                    '/var/www/app',
                    '/opt/orbit/apps',
                    'composer.json',
                ),
            );
    });

    it('normalizes Composer versions through the shared SemVer normalizer', function (): void {
        [$manager] = composer_tool_manager([]);

        expect($manager->normalizeVersion('v5.17.0'))->toBe('5.17.0');
        expect($manager->normalizeVersion('dev-main'))->toBeNull();
    });

    it('uses the exact private-root installed version only for a no-op candidate', function (): void {
        [$manager, $ssh] = composer_tool_manager([
            composer_result(stderr: 'Nothing to install, update or remove'),
            composer_result(composer_show(package: 'laravel/installer', version: 'v5.16.0')),
        ]);

        $version = $manager->candidateVersion(
            composer_tool_node(),
            'laravel/installer',
            ToolOperation::Update,
        );

        expect($version)->toBe('v5.16.0');
        expect($ssh->arguments())->toBe([
            composer_update_arguments('laravel/installer', dryRun: true),
            composer_show_arguments(),
        ]);
    });

    it('fails closed when a no-op fallback does not contain the exact target package', function (string $show): void {
        [$manager] = composer_tool_manager([
            composer_result('Nothing to install, update or remove'),
            composer_result($show),
        ]);

        expect(fn () => $manager->candidateVersion(
            composer_tool_node(),
            'laravel/installer',
            ToolOperation::Update,
        ))
            ->toThrow(ToolManagerException::class);
    })->with([
        'wrong package' => [composer_show(package: 'laravel/framework', version: 'v12.0.0')],
        'empty installed list' => [composer_show_empty()],
        'duplicate target' => [composer_show_entries([
            ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
            ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
        ])],
    ]);

    it('returns null only for a bounded exact package-not-found result', function (): void {
        [$manager, $ssh] = composer_tool_manager([
            composer_result(
                exitCode: 2,
                stderr: 'Could not find a matching version of package laravel/installer. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (stable).',
            ),
        ]);

        $version = $manager->candidateVersion(
            composer_tool_node(),
            'laravel/installer',
            ToolOperation::Install,
        );

        expect($version)->toBeNull();
        expect($ssh->arguments())->toBe([
            composer_require_arguments('laravel/installer', dryRun: true),
        ]);
    });

    it('fails closed and sanitizes invalid candidate results', function (CommandResult $result, string $step): void {
        [$manager] = composer_tool_manager([$result]);

        expect(fn () => $manager->candidateVersion(
            composer_tool_node(),
            'laravel/installer',
            ToolOperation::Install,
        ))->toThrow(function (ToolManagerException $exception) use ($step): void {
            expect($exception->step)->toBe($step);
            expect($exception->result?->stdout)->toBeEmpty();
            expect($exception->result?->stderr)->toBeEmpty();
        });
    })->with([
        'wrong package' => [composer_result('  - Installing laravel/framework (v12.0.0)'), 'candidate-version'],
        'duplicate target' => [
            composer_result("  - Installing laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)"),
            'candidate-version',
        ],
        'conflicting target' => [
            composer_result("  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.18.0)"),
            'candidate-version',
        ],
        'unknown nonzero' => [composer_result('secret', exitCode: 2, stderr: 'secret error'), 'candidate-version'],
        'wrong-package not found' => [
            composer_result(
                exitCode: 2,
                stderr: 'Could not find a matching version of package laravel/framework. Check the package spelling.',
            ),
            'candidate-version',
        ],
        'truncated' => [composer_result('secret', stderr: 'secret error', truncated: true), 'ssh'],
    ]);

    it('rejects removal as a candidate operation before remote I/O', function (): void {
        [$manager, $ssh] = composer_tool_manager([]);

        expect(fn () => $manager->candidateVersion(
            composer_tool_node(),
            'laravel/installer',
            ToolOperation::Remove,
        ))
            ->toThrow(ToolManagerException::class);
        expect($ssh->arguments())->toBeEmpty();
    });

    it('returns null only when the exact target is absent from valid show output', function (): void {
        [$manager] = composer_tool_manager([
            composer_result(composer_show(package: 'laravel/framework', version: 'v12.0.0')),
        ]);

        $version = $manager->installedVersion(composer_tool_node(), 'laravel/installer');

        expect($version)->toBeNull();
    });

    it('fails closed on malformed installed-version output', function (CommandResult $result, string $step): void {
        [$manager] = composer_tool_manager([$result]);

        expect(fn () => $manager->installedVersion(composer_tool_node(), 'laravel/installer'))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)->toBe($step);
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
            });
    })->with([
        'nonzero' => [composer_result('secret', exitCode: 3, stderr: 'secret error'), 'installed-version'],
        'truncated' => [composer_result('secret', stderr: 'secret error', truncated: true), 'ssh'],
        'invalid JSON' => [composer_result('{'), 'installed-version'],
        'missing installed list' => [composer_result('{}'), 'installed-version'],
        'non-list installed value' => [composer_result('{"installed":{}}'), 'installed-version'],
        'malformed entry' => [
            composer_result(composer_show_entries([['name' => 'laravel/installer']])),
            'installed-version',
        ],
        'duplicate target' => [
            composer_result(composer_show_entries([
                ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
                ['name' => 'laravel/installer', 'version' => 'v5.17.0'],
            ])),
            'installed-version',
        ],
        'control-bearing version' => [
            composer_result(composer_show(package: 'laravel/installer', version: "v5.16.0\0hidden")),
            'installed-version',
        ],
        'oversized version' => [
            composer_result(composer_show('laravel/installer', str_repeat('1', times: 256))),
            'installed-version',
        ],
    ]);

    it('fails closed on malformed manager-version output', function (CommandResult $result, string $step): void {
        [$manager] = composer_tool_manager([$result]);

        expect(fn () => $manager->managerVersion(composer_tool_node()))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)->toBe($step);
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
            });
    })->with([
        'nonzero' => [composer_result('secret', exitCode: 4, stderr: 'secret error'), 'manager-version'],
        'truncated' => [composer_result('secret', stderr: 'secret error', truncated: true), 'ssh'],
        'empty' => [composer_result(), 'manager-version'],
        'control bearing' => [composer_result("Composer version 2.8.12\0hidden\n"), 'manager-version'],
        'oversized' => [composer_result(str_repeat('1', times: 256)."\n"), 'manager-version'],
    ]);

    it('fails closed and sanitizes failed mutations', function (
        Closure $operation,
        array $arguments,
        string $step,
    ): void {
        [$manager, $ssh] = composer_tool_manager([
            composer_result('secret stdout', exitCode: 5, stderr: 'secret stderr'),
        ]);

        expect(fn () => $operation($manager, composer_tool_node()))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)->toBe($step);
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
                expect($exception->getMessage())->not->toContain('secret');
            });
        expect($ssh->arguments())->toBe([$arguments]);
    })->with([
        'install' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->install($node, 'laravel/installer'),
            composer_require_arguments('laravel/installer'),
            'install',
        ],
        'update' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->update($node, 'laravel/installer'),
            composer_update_arguments('laravel/installer'),
            'update',
        ],
        'removal plan' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->planRemoval($node, 'laravel/installer'),
            composer_remove_arguments('laravel/installer', dryRun: true),
            'removal-plan',
        ],
        'remove' => [
            static fn (ComposerToolManager $manager, Node $node) => $manager->remove($node, 'laravel/installer'),
            composer_remove_arguments('laravel/installer'),
            'remove',
        ],
    ]);
});

/**
 * @param list<CommandResult> $results
 * @return array{ComposerToolManager, ToolManagerFakeSshExecutor}
 */
function composer_tool_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new ComposerToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: $ssh,
                keys: composer_tool_keys(),
                knownHosts: composer_tool_known_hosts(),
            ),
            parser: new ComposerDryRunVersionParser,
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

function composer_tool_node(
    string $platform = 'linux',
    LifecycleStatus $status = LifecycleStatus::Active,
    ?RoleName $role = RoleName::AppDev,
    ?LifecycleStatus $roleStatus = LifecycleStatus::Active,
): Node {
    $node = new Node([
        'name' => 'composer-tool-node',
        'status' => $status,
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_address' => '10.8.0.45',
    ]);
    $roles = [];

    if ($role !== null && $roleStatus !== null) {
        $roles[] = new NodeRole([
            'role' => $role,
            'status' => $roleStatus,
        ]);
    }

    $node->setRelation('roles', new EloquentCollection($roles));

    return $node;
}

function composer_result(
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

function composer_show(string $package, string $version): string
{
    return composer_show_entries([
        ['name' => $package, 'version' => $version],
    ]);
}

function composer_show_empty(): string
{
    return composer_show_entries([]);
}

/** @param list<array<string, string>> $entries */
function composer_show_entries(array $entries): string
{
    return json_encode(['installed' => $entries], flags: JSON_THROW_ON_ERROR);
}

/** @return non-empty-list<string> */
function composer_show_arguments(): array
{
    return [...composer_prefix(), 'show', '--format=json', '--no-ansi'];
}

/** @return non-empty-list<string> */
function composer_require_arguments(string $package, bool $dryRun = false): array
{
    return composer_operation_arguments('require', "{$package}:*", $dryRun);
}

/** @return non-empty-list<string> */
function composer_update_arguments(string $package, bool $dryRun = false): array
{
    return composer_operation_arguments('update', $package, $dryRun);
}

/** @return non-empty-list<string> */
function composer_remove_arguments(string $package, bool $dryRun = false): array
{
    return composer_operation_arguments('remove', $package, $dryRun);
}

/**
 * @return non-empty-list<string>
 * @mago-expect lint:no-boolean-flag-parameter The flag selects the fixed live or dry-run command fixture.
 */
function composer_operation_arguments(string $operation, string $package, bool $dryRun): array
{
    $arguments = [...composer_prefix(), $operation, $package];

    if ($dryRun) {
        $arguments[] = '--dry-run';
    }

    return [
        ...$arguments,
        '--no-interaction',
        '--no-ansi',
        '--no-progress',
        '--no-audit',
        '--with-all-dependencies',
    ];
}

/** @return non-empty-list<string> */
function composer_prefix(): array
{
    return ['env', 'COMPOSER_HOME=/opt/orbit/composer', '/usr/bin/composer', 'global'];
}

function composer_tool_keys(): SshKeyProvider
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

function composer_tool_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
