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
            ->toContain('d79ef822ab8136e393ed5f86e2b56afc68d04874')
            ->toContain("expected_version='Homebrew 7.0.0'")
            ->toContain('status --porcelain=v1 --untracked-files=all')
            ->toContain('HOMEBREW_NO_AUTO_UPDATE=1')
            ->toContain('"$prefix/bin/brew" config >/dev/null')
            ->not->toContain('install.sh');
    });

    it('upgrades an Orbit-owned Homebrew prefix to the pinned revision before verification', function (): void {
        [$manager, $ssh] = homebrew_tool_manager([homebrew_result()]);

        $manager->materialize(homebrew_tool_node(role: null));

        $program = $ssh->commands[0]->input;
        $upgradeGate = strpos($program, 'current_revision=$(git -C "$repository" rev-parse HEAD)');
        $originCheck = strpos($program, 'config --get remote.origin.url');
        $cleanTree = strpos($program, 'status --porcelain=v1 --untracked-files=all');
        $fetch = strpos($program, 'fetch --filter=blob:none origin "$expected_revision"');
        $checkout = is_int($fetch)
            ? strpos($program, '-c advice.detachedHead=false checkout --detach "$expected_revision"', $fetch)
            : false;
        $pinnedHead = strpos($program, 'test "$(git -C "$repository" rev-parse HEAD)" = "$expected_revision"');
        $tagPresent = strpos($program, 'show-ref --verify --quiet "refs/tags/$expected_tag"');
        $tagFetch = strpos($program, 'fetch --filter=blob:none origin tag "$expected_tag"');
        $describeCache = strpos($program, 'rm -rf -- "$repository/.git/describe-cache"');
        $tagPointsAtPin = strpos($program, 'rev-parse --verify "$expected_tag^{commit}"');
        $verifyVersion = strpos($program, '"$prefix/bin/brew" --version');

        expect($program)
            ->toContain('[ "$current_revision" != "$expected_revision" ]')
            ->toContain('expected_tag=${expected_version#Homebrew }')
            ->toContain("expected_version='Homebrew 7.0.0'");
        expect($upgradeGate)->toBeInt();
        expect($originCheck)->toBeInt()->toBeGreaterThan($upgradeGate);
        expect($cleanTree)->toBeInt()->toBeGreaterThan($upgradeGate);
        expect($fetch)->toBeInt()->toBeGreaterThan($originCheck)->toBeGreaterThan($cleanTree);
        expect($checkout)->toBeInt()->toBeGreaterThan($fetch);
        expect($pinnedHead)->toBeInt()->toBeGreaterThan($checkout);
        expect($tagPresent)->toBeInt()->toBeGreaterThan($pinnedHead);
        expect($tagFetch)->toBeInt()->toBeGreaterThan($tagPresent);
        expect($describeCache)->toBeInt()->toBeGreaterThan($tagFetch);
        expect($tagPointsAtPin)->toBeInt()->toBeGreaterThan($describeCache);
        expect($verifyVersion)->toBeInt()->toBeGreaterThan($tagPointsAtPin);

        $script = tempnam(sys_get_temp_dir(), 'orbit-homebrew-');
        file_put_contents($script, $program);

        try {
            exec('bash -n '.escapeshellarg($script).' 2>&1', $syntaxOutput, $syntaxStatus);
            expect($syntaxStatus)->toBe(0);
        } finally {
            unlink($script);
        }
    });

    it('fetches the pinned version tag after a SHA-only upgrade so git describe reports Homebrew 7.0.0', function (): void {
        [$manager, $ssh] = homebrew_tool_manager([homebrew_result()]);

        $manager->materialize(homebrew_tool_node(role: null));

        $program = $ssh->commands[0]->input;
        $root = sys_get_temp_dir().'/orbit-homebrew-tag-'.bin2hex(random_bytes(8));
        $origin = $root.'/origin.git';
        $local = $root.'/Homebrew';

        try {
            expect(mkdir($root, 0700, true))->toBeTrue();
            homebrew_git_run($root, ['git', 'init', '--bare', '--initial-branch=main', $origin]);

            $seed = $root.'/seed';
            expect(mkdir($seed, 0700, true))->toBeTrue();
            homebrew_git_run($seed, ['git', 'init', '--initial-branch=main']);
            homebrew_git_run($seed, ['git', 'config', 'user.name', 'Orbit Tests']);
            homebrew_git_run($seed, ['git', 'config', 'user.email', 'orbit@example.test']);
            homebrew_git_run($seed, ['git', 'remote', 'add', 'origin', $origin]);
            file_put_contents($seed.'/previous', "previous pin\n");
            homebrew_git_run($seed, ['git', 'add', 'previous']);
            homebrew_git_run($seed, ['git', 'commit', '-m', 'previous pin']);
            homebrew_git_run($seed, ['git', 'tag', '6.0.22']);
            homebrew_git_run($seed, ['git', 'push', 'origin', 'HEAD:main', '6.0.22']);

            homebrew_git_run($root, ['git', 'clone', $origin, $local]);

            file_put_contents($seed.'/current', "current pin\n");
            homebrew_git_run($seed, ['git', 'add', 'current']);
            homebrew_git_run($seed, ['git', 'commit', '-m', 'current pin']);
            homebrew_git_run($seed, ['git', 'tag', '7.0.0']);
            $expectedRevision = homebrew_git_run($seed, ['git', 'rev-parse', 'HEAD']);
            homebrew_git_run($seed, ['git', 'push', 'origin', 'HEAD:main', '7.0.0']);

            homebrew_git_run($local, ['git', 'fetch', '--filter=blob:none', 'origin', $expectedRevision]);
            homebrew_git_run($local, ['git', '-c', 'advice.detachedHead=false', 'checkout', '--detach', $expectedRevision]);
            expect(mkdir($local.'/.git/describe-cache', 0700, true))->toBeTrue();
            file_put_contents($local.'/.git/describe-cache/'.$expectedRevision, "6.0.22-1-gd79ef82\n");

            expect(homebrew_git_run($local, ['git', 'describe', '--tags', '--dirty', '--abbrev=7']))
                ->not
                ->toBe('7.0.0');
            expect(homebrew_git_run($local, ['git', 'show-ref', '--verify', '--quiet', 'refs/tags/7.0.0'], allowFailure: true))
                ->toBe(1);

            $recipe = $root.'/ensure-tag.sh';
            file_put_contents($recipe, <<<BASH
                set -eu
                repository={$local}
                expected_revision={$expectedRevision}
                expected_version='Homebrew 7.0.0'
                expected_tag=\${expected_version#Homebrew }
                if ! git -C "\$repository" show-ref --verify --quiet "refs/tags/\$expected_tag"; then
                    git -C "\$repository" fetch --filter=blob:none origin tag "\$expected_tag"
                    rm -rf -- "\$repository/.git/describe-cache"
                fi
                test "\$(git -C "\$repository" rev-parse --verify "\$expected_tag^{commit}")" = "\$expected_revision"
                BASH);

            expect($program)
                ->toContain('show-ref --verify --quiet "refs/tags/$expected_tag"')
                ->toContain('fetch --filter=blob:none origin tag "$expected_tag"')
                ->toContain('rm -rf -- "$repository/.git/describe-cache"')
                ->toContain('rev-parse --verify "$expected_tag^{commit}"');

            exec('bash '.escapeshellarg($recipe).' 2>&1', $recipeOutput, $recipeStatus);
            expect($recipeStatus)->toBe(0);

            $described = homebrew_git_run($local, ['git', 'describe', '--tags', '--dirty', '--abbrev=7']);
            expect($described)->toBe('7.0.0');
            expect('Homebrew '.$described)->toBe('Homebrew 7.0.0');
            expect(is_dir($local.'/.git/describe-cache'))->toBeFalse();
        } finally {
            homebrew_delete_directory($root);
        }
    });

    it('uses fixed Core-only forced-bottle argv for the complete lifecycle', function (): void {
        [$manager, $ssh] = homebrew_tool_manager([
            homebrew_result("Homebrew 7.0.0\n"),
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

        expect($manager->managerVersion($node))->toBe('Homebrew 7.0.0');
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

    it('rejects a Homebrew manager version that is not the pinned release', function (string $version): void {
        [$manager] = homebrew_tool_manager([
            homebrew_result($version."\n"),
        ]);

        expect(fn () => $manager->managerVersion(homebrew_tool_node()))
            ->toThrow(ToolManagerException::class, 'unsupported version');
    })->with([
        'previous Orbit pin' => ['Homebrew 6.0.6'],
        'newer patch' => ['Homebrew 7.0.1'],
        'unrelated tool' => ['Homebrew 5.0.0'],
    ]);

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

/**
 * @param  list<string>  $arguments
 */
function homebrew_git_run(string $directory, array $arguments, bool $allowFailure = false): string|int
{
    $command = implode(' ', array_map(escapeshellarg(...), $arguments));
    exec('cd '.escapeshellarg($directory).' && '.$command.' 2>&1', $output, $status);

    if ($allowFailure) {
        return $status;
    }

    expect($status)->toBe(0);

    return trim(implode("\n", $output));
}

function homebrew_delete_directory(string $directory): void
{
    if ($directory === '' || ! is_dir($directory)) {
        return;
    }

    exec('rm -rf -- '.escapeshellarg($directory));
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
