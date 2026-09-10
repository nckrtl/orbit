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
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(HomebrewToolManager::class, function (): void {
    it('is the role-independent Linux Homebrew manager', function (
        string $platform,
        ?RoleName $role,
        bool $supported,
    ): void {
        [$manager] = homebrew_tool_manager([]);

        expect($manager)
            ->toBeInstanceOf(ToolManager::class)
            ->and($manager->name())
            ->toBe(ToolManagerName::Brew)
            ->and($manager->supportsNode(homebrew_tool_node($platform, $role)))
            ->toBe($supported);
    })->with([
        'app-dev' => ['linux', RoleName::AppDev, true],
        'app-prod' => ['linux', RoleName::AppProd, true],
        'gateway' => ['linux', RoleName::Gateway, true],
        'roleless' => ['linux', null, true],
        'non-Linux' => ['darwin', RoleName::AppDev, false],
    ]);

    it('accepts only unqualified lowercase formula names', function (string $package, bool $valid): void {
        [$manager] = homebrew_tool_manager([]);

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple' => ['herdr', true],
        'versioned formula' => ['php@8.5', true],
        'punctuation' => ['libc++_tool.1', true],
        'empty' => ['', false],
        'uppercase' => ['Herdr', false],
        'tap' => ['homebrew/core/herdr', false],
        'other tap' => ['example/tap/herdr', false],
        'cask option' => ['--cask', false],
        'URL' => ['https://example.com/herdr.rb', false],
        'local formula' => ['./herdr.rb', false],
        'Git ref' => ['herdr#main', false],
        'whitespace' => ['herdr service', false],
        'oversized' => [str_repeat('a', times: 256), false],
    ]);

    it('materializes or recognizes the exact protected Homebrew scope', function (): void {
        [$manager, $ssh] = homebrew_tool_manager([homebrew_result()]);

        $manager->materialize(homebrew_tool_node(role: null));

        expect($ssh->arguments())
            ->toBe([['sudo', 'bash', '-seu', '--', 'orbit']])
            ->and($ssh->commands[0]->input)
            ->toContain(
                'apt-get install --yes --no-install-recommends --no-remove -- build-essential procps curl file git ca-certificates',
            )
            ->toContain('https://github.com/Homebrew/brew')
            ->toContain('2b3683acbeac84c27669195235785694b72e253e')
            ->toContain("expected_version='Homebrew 6.0.6'")
            ->toContain('status --porcelain=v1 --untracked-files=all')
            ->toContain('HOMEBREW_NO_AUTO_UPDATE=1')
            ->toContain('"$prefix/bin/brew" config >/dev/null')
            ->not->toContain('install.sh');
    });

    it('uses fixed Core-only forced-bottle argv for the complete lifecycle', function (): void {
        [$manager, $ssh] = homebrew_tool_manager([
            homebrew_result("Homebrew 6.0.6\n"),
            homebrew_result("x86_64\n"),
            homebrew_result(homebrew_formula()),
            homebrew_result("herdr 0.8.2\n"),
            homebrew_result("x86_64\n"),
            homebrew_result(homebrew_formula()),
            homebrew_result(),
            homebrew_result("x86_64\n"),
            homebrew_result(homebrew_formula()),
            homebrew_result(),
            homebrew_result(),
        ]);
        $node = homebrew_tool_node();

        expect($manager->managerVersion($node))->toBe('Homebrew 6.0.6');
        expect($manager->candidateVersion($node, 'herdr', ToolOperation::Install))->toBe('0.9.0');
        expect($manager->installedVersion($node, 'herdr'))->toBe('0.8.2');
        $manager->install($node, 'herdr');
        $manager->update($node, 'herdr');
        expect($manager->planRemoval($node, 'herdr')->packages)->toBe(['herdr']);
        $manager->remove($node, 'herdr');

        $prefix = homebrew_arguments();
        expect($ssh->arguments())->toBe([
            [...$prefix, '--version'],
            ['/usr/bin/uname', '-m'],
            [...$prefix, 'info', '--json=v2', '--formula', 'homebrew/core/herdr'],
            [...$prefix, 'list', '--versions', '--formula', 'homebrew/core/herdr'],
            ['/usr/bin/uname', '-m'],
            [...$prefix, 'info', '--json=v2', '--formula', 'homebrew/core/herdr'],
            [...$prefix, 'install', '--formula', '--force-bottle', 'homebrew/core/herdr'],
            ['/usr/bin/uname', '-m'],
            [...$prefix, 'info', '--json=v2', '--formula', 'homebrew/core/herdr'],
            [...$prefix, 'upgrade', '--formula', '--force-bottle', 'homebrew/core/herdr'],
            [...$prefix, 'uninstall', '--formula', 'homebrew/core/herdr'],
        ]);
    });

    it('selects the arm64 Linux bottle and normalizes versions', function (): void {
        [$manager] = homebrew_tool_manager([
            homebrew_result("aarch64\n"),
            homebrew_result(homebrew_formula(architecture: 'arm64_linux')),
        ]);

        expect($manager->candidateVersion(homebrew_tool_node(), 'herdr', ToolOperation::Update))
            ->toBe('0.9.0')
            ->and($manager->normalizeVersion('v0.9'))
            ->toBe('0.9.0')
            ->and($manager->normalizeVersion('head'))
            ->toBeNull();
    });

    it('returns null only for exact absent formula and keg results', function (): void {
        [$candidateManager] = homebrew_tool_manager([
            homebrew_result("x86_64\n"),
            homebrew_result(
                exitCode: 1,
                stderr: "Error: No available formula with the name \"homebrew/core/missing\".\n",
            ),
        ]);
        [$installedManager] = homebrew_tool_manager([homebrew_result(exitCode: 1)]);

        expect($candidateManager->candidateVersion(homebrew_tool_node(), 'missing', ToolOperation::Install))
            ->toBeNull()
            ->and($installedManager->installedVersion(homebrew_tool_node(), 'missing'))
            ->toBeNull();
    });

    it('fails closed when Core metadata does not prove a compatible bottle', function (array $changes): void {
        [$manager] = homebrew_tool_manager([
            homebrew_result("x86_64\n"),
            homebrew_result(homebrew_formula($changes)),
        ]);

        expect(fn () => $manager->candidateVersion(homebrew_tool_node(), 'herdr', ToolOperation::Install))
            ->toThrow(ToolManagerException::class, 'compatible verified bottle');
    })->with([
        'wrong name' => [['name' => 'other']],
        'qualified full name' => [['full_name' => 'other/tap/herdr']],
        'wrong tap' => [['tap' => 'other/tap']],
        'no stable version' => [['stable' => null]],
        'no bottle declaration' => [['has_bottle' => false]],
        'no architecture bottle' => [['architecture' => 'arm64_linux']],
        'missing checksum' => [['sha256' => null]],
        'mismatched checksum URL' => [['url_sha256' => str_repeat('b', times: 64)]],
        'disabled formula' => [['disabled' => true]],
        'cask response' => [['casks' => [['name' => 'herdr']]]],
    ]);

    it('rejects unsupported operations, nodes, packages, and architectures before mutation', function (): void {
        [$removeManager, $removeSsh] = homebrew_tool_manager([]);
        [$nodeManager, $nodeSsh] = homebrew_tool_manager([]);
        [$packageManager, $packageSsh] = homebrew_tool_manager([]);
        [$architectureManager] = homebrew_tool_manager([homebrew_result("riscv64\n")]);

        expect(fn () => $removeManager->candidateVersion(homebrew_tool_node(), 'herdr', ToolOperation::Remove))
            ->toThrow(ToolManagerException::class)
            ->and(fn () => $nodeManager->install(homebrew_tool_node('darwin'), 'herdr'))
            ->toThrow(ToolManagerException::class)
            ->and(fn () => $packageManager->install(homebrew_tool_node(), 'other/tap/herdr'))
            ->toThrow(ToolManagerException::class)
            ->and(fn () => $architectureManager->candidateVersion(
                homebrew_tool_node(),
                'herdr',
                ToolOperation::Install,
            ))
            ->toThrow(ToolManagerException::class, 'no supported Homebrew bottle');
        expect($removeSsh->arguments())->toBeEmpty();
        expect($nodeSsh->arguments())->toBeEmpty();
        expect($packageSsh->arguments())->toBeEmpty();
    });

    it('returns bounded sanitized failures for probes and mutations', function (): void {
        [$manager] = homebrew_tool_manager([
            homebrew_result('secret', exitCode: 7, stderr: 'secret error'),
        ]);

        expect(fn () => $manager->managerVersion(homebrew_tool_node()))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('manager-version');
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
                expect($exception->getMessage())->not->toContain('secret');
            });
    });

    it('returns bounded sanitized failures for bootstrap and formula metadata', function (): void {
        [$bootstrapManager] = homebrew_tool_manager([
            homebrew_result('private bootstrap output', exitCode: 6, stderr: 'private bootstrap error'),
        ]);
        [$formulaManager, $formulaSsh] = homebrew_tool_manager([
            homebrew_result("x86_64\n"),
            homebrew_result('private metadata', exitCode: 8, stderr: 'private metadata error'),
        ]);

        expect(fn () => $bootstrapManager->materialize(homebrew_tool_node()))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('materialize');
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
            });
        expect(fn () => $formulaManager->install(homebrew_tool_node(), 'herdr'))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)->toBe('candidate-version');
                expect($exception->result?->stdout)->toBeEmpty();
                expect($exception->result?->stderr)->toBeEmpty();
            });
        expect($formulaSsh->arguments())->toHaveCount(2);
        expect($formulaSsh->arguments())
            ->not
            ->toContain(
                [...homebrew_arguments(), 'install', '--formula', '--force-bottle', 'homebrew/core/herdr'],
            );
    });

    it('rejects malformed installed state instead of adopting it', function (CommandResult $result): void {
        [$manager] = homebrew_tool_manager([$result]);

        expect(fn () => $manager->installedVersion(homebrew_tool_node(), 'herdr'))
            ->toThrow(ToolManagerException::class);
    })->with([
        'wrong package' => [homebrew_result("other 0.9.0\n")],
        'multiple versions' => [homebrew_result("herdr 0.8.2 0.9.0\n")],
        'unknown failure' => [homebrew_result(exitCode: 1, stderr: 'unexpected')],
        'truncated' => [homebrew_result('secret', truncated: true)],
    ]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{HomebrewToolManager, ToolManagerFakeSshExecutor}
 */
function homebrew_tool_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new HomebrewToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: $ssh,
                keys: homebrew_tool_keys(),
                knownHosts: homebrew_tool_known_hosts(),
            ),
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

function homebrew_tool_node(string $platform = 'linux', ?RoleName $role = RoleName::AppDev): Node
{
    $node = new Node([
        'name' => 'homebrew-tool-node',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.44',
    ]);
    $roles = $role === null
        ? []
        : [new NodeRole([
            'role' => $role,
            'status' => LifecycleStatus::Active,
        ])];
    $node->setRelation('roles', collect($roles));

    return $node;
}

/** @param array<string, mixed> $changes */
function homebrew_formula(array $changes = [], string $architecture = 'x86_64_linux'): string
{
    $architecture = is_string($changes['architecture'] ?? null) ? $changes['architecture'] : $architecture;
    $sha256 = array_key_exists('sha256', $changes) ? $changes['sha256'] : str_repeat('a', times: 64);
    $urlSha256 = is_string($changes['url_sha256'] ?? null) ? $changes['url_sha256'] : $sha256;
    $file = [
        'url' => "https://ghcr.io/v2/homebrew/core/herdr/blobs/sha256:{$urlSha256}",
        'sha256' => $sha256,
    ];

    return json_encode([
        'formulae' => [[
            'name' => $changes['name'] ?? 'herdr',
            'full_name' => $changes['full_name'] ?? 'herdr',
            'tap' => $changes['tap'] ?? 'homebrew/core',
            'versions' => [
                'stable' => array_key_exists('stable', $changes) ? $changes['stable'] : '0.9.0',
                'bottle' => $changes['has_bottle'] ?? true,
            ],
            'bottle' => ['stable' => ['files' => [$architecture => $file]]],
            'disabled' => $changes['disabled'] ?? false,
            'service' => ['run' => ['herdr', 'server']],
        ]],
        'casks' => $changes['casks'] ?? [],
    ], JSON_THROW_ON_ERROR);
}

/** @return non-empty-list<string> */
function homebrew_arguments(): array
{
    return [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin',
        '/home/linuxbrew/.linuxbrew/bin/brew',
    ];
}

function homebrew_result(
    string $stdout = '',
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 10, $truncated);
}

function homebrew_tool_keys(): SshKeyProvider
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

function homebrew_tool_known_hosts(): KnownHostsStore
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
