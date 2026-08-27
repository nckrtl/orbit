<?php

declare(strict_types=1);

use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use Tests\Support\ToolManagerFakeSshExecutor;

/** @mago-expect lint:halstead The focused matrix keeps each APT input, parser, and fixed-command boundary observable. */
describe(AptToolManager::class, function (): void {
    it('implements the APT tool manager adapter', function (): void {
        expect(new AptToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: new ToolManagerFakeSshExecutor,
                keys: apt_tool_keys(),
                knownHosts: apt_tool_known_hosts(),
            ),
            versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
        ))->toBeInstanceOf(ToolManager::class);
    });

    it('supports only Linux nodes and identifies itself as APT', function (): void {
        [$manager] = apt_tool_manager([]);

        expect($manager->name())
            ->toBe(ToolManagerName::Apt)
            ->and($manager->supportsNode(apt_tool_node('linux')))
            ->toBeTrue()
            ->and($manager->supportsNode(apt_tool_node('darwin')))
            ->toBeFalse();
    });

    it('accepts only strict Debian package names', function (string $package, bool $valid): void {
        [$manager] = apt_tool_manager([]);

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple package' => ['jq', true],
        'versioned package name' => ['php8.5-cli', true],
        'plus in package name' => ['libc++1', true],
        'digit prefix with a letter' => ['1password-cli', true],
        'single character' => ['a', false],
        'single digit' => ['1', false],
        'digits only' => ['12', false],
        'long digits only' => [str_repeat('1', times: 128), false],
        'leading option' => ['-o', false],
        'uppercase' => ['Jq', false],
        'newline injection' => ["jq\nwhoami", false],
        'nul injection' => ["jq\0whoami", false],
        'oversized' => [str_repeat('a', times: 129), false],
        'architecture qualifier' => ['jq:amd64', false],
        'version qualifier' => ['jq=1.7', false],
        'wildcard' => ['jq*', false],
        'repository selector' => ['resolute/jq', false],
        'whitespace' => ['jq package', false],
        'empty' => ['', false],
    ]);

    it('rejects an invalid package before node I/O', function (Closure $operation): void {
        [$manager, $ssh] = apt_tool_manager([]);

        expect(fn () => $operation($manager, apt_tool_node()))
            ->toThrow(function (ToolManagerException $exception): void {
                expect($exception->step)
                    ->toBe('package')
                    ->and($exception->result)
                    ->toBeNull()
                    ->and($exception->getMessage())
                    ->not->toContain('jq:amd64');
            });
        expect($ssh->arguments())->toBeEmpty();
    })->with([
        'candidate probe' => [
            static fn (AptToolManager $manager, Node $node): ?string => $manager->candidateVersion(
                $node,
                'jq:amd64',
                ToolOperation::Install,
            ),
        ],
        'installed probe' => [
            static fn (AptToolManager $manager, Node $node): ?string => $manager->installedVersion($node, 'jq:amd64'),
        ],
        'install' => [
            static fn (AptToolManager $manager, Node $node) => $manager->install($node, 'jq:amd64'),
        ],
        'update' => [
            static fn (AptToolManager $manager, Node $node) => $manager->update($node, 'jq:amd64'),
        ],
        'removal plan' => [
            static fn (AptToolManager $manager, Node $node) => $manager->planRemoval($node, 'jq:amd64'),
        ],
        'remove' => [
            static fn (AptToolManager $manager, Node $node) => $manager->remove($node, 'jq:amd64'),
        ],
    ]);

    it('uses fixed argv and retains raw APT versions through the complete lifecycle', function (): void {
        [$manager, $ssh] = apt_tool_manager([
            apt_result("apt 3.0.3 (amd64)\nSupported modules:\n"),
            apt_result("jq:\n  Candidate: 1:2.4.3-1ubuntu2\n"),
            apt_result("install ok installed\n1:2.4.3-1ubuntu2\n"),
            apt_result(),
            apt_result(),
            apt_result("Remv jq [1.7.1-3ubuntu0.26.04.1]\n"),
            apt_result(),
        ]);
        $node = apt_tool_node();

        $managerVersion = $manager->managerVersion($node);
        $candidateVersion = $manager->candidateVersion($node, 'jq', ToolOperation::Install);
        $installedVersion = $manager->installedVersion($node, 'jq');
        $manager->install($node, 'jq');
        $manager->update($node, 'jq');
        $removalPlan = $manager->planRemoval($node, 'jq');
        $manager->remove($node, 'jq');

        expect($managerVersion)->toBe('apt 3.0.3 (amd64)');
        expect($candidateVersion)->toBe('1:2.4.3-1ubuntu2');
        expect($installedVersion)->toBe('1:2.4.3-1ubuntu2');
        expect($removalPlan->packages)->toBe(['jq']);
        expect($removalPlan->removesOnly('jq'))->toBeTrue();
        expect($ssh->arguments())->toBe([
            ['apt-get', '--version'],
            ['apt-cache', 'policy', '--', 'jq'],
            ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', 'jq'],
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            ['apt-get', '--simulate', 'remove', '--', 'jq'],
            ['sudo', 'apt-get', 'remove', '--yes', '--', 'jq'],
        ]);
        expect($ssh->arguments())
            ->each(static fn ($arguments) => $arguments->not->toContain('autoremove'));
    });

    it('normalizes raw Debian versions through the approved normalizer', function (): void {
        [$manager] = apt_tool_manager([]);

        expect($manager->normalizeVersion('1:2.4.3-1ubuntu2'))
            ->toBe('2.4.3')
            ->and($manager->normalizeVersion('1.0~rc1-1'))
            ->toBeNull();
    });

    it('returns null for the explicit absent candidate', function (): void {
        [$manager, $ssh] = apt_tool_manager([
            apt_result("jq:\n  Candidate: (none)\n"),
        ]);

        $version = $manager->candidateVersion(apt_tool_node(), 'jq', ToolOperation::Install);

        expect($version)->toBeNull();
        expect($ssh->arguments())->toBe([
            ['apt-cache', 'policy', '--', 'jq'],
        ]);
    });

    it('returns null for the exact not-installed status', function (): void {
        [$manager, $ssh] = apt_tool_manager([
            apt_result("unknown ok not-installed\n"),
        ]);

        $version = $manager->installedVersion(apt_tool_node(), 'jq');

        expect($version)->toBeNull();
        expect($ssh->arguments())->toBe([
            ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', 'jq'],
        ]);
    });

    it('fails closed on an invalid manager-version result', function (CommandResult $result, string $step): void {
        [$manager] = apt_tool_manager([$result]);

        expect(fn () => $manager->managerVersion(apt_tool_node()))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [apt_result('secret stdout', exitCode: 11, stderr: 'secret stderr'), 'manager-version'],
        'truncated' => [apt_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'missing' => [apt_result(), 'manager-version'],
        'control bearing' => [apt_result("apt 3.0\0hidden\n"), 'manager-version'],
        'oversized' => [apt_result(str_repeat('a', times: 256)."\n"), 'manager-version'],
    ]);

    it('fails closed on ambiguous or malformed candidate output', function (CommandResult $result, string $step): void {
        [$manager] = apt_tool_manager([$result]);

        expect(fn () => $manager->candidateVersion(apt_tool_node(), 'jq', ToolOperation::Install))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [apt_result('secret stdout', exitCode: 12, stderr: 'secret stderr'), 'candidate-version'],
        'truncated' => [apt_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'missing' => [apt_result("jq:\n  Installed: (none)\n"), 'candidate-version'],
        'duplicate' => [apt_result("  Candidate: 1.0.0\n  Candidate: 2.0.0\n"), 'candidate-version'],
        'multiple tokens' => [apt_result("  Candidate: 1.0.0 unexpected\n"), 'candidate-version'],
        'control bearing' => [apt_result("  Candidate: 1.0\0hidden\n"), 'candidate-version'],
        'oversized' => [apt_result('  Candidate: '.str_repeat('1', times: 256)."\n"), 'candidate-version'],
    ]);

    it('fails closed on ambiguous or malformed installed-version output', function (
        CommandResult $result,
        string $step,
    ): void {
        [$manager] = apt_tool_manager([$result]);

        expect(fn () => $manager->installedVersion(apt_tool_node(), 'jq'))
            ->toThrow(function (ToolManagerException $exception) use ($step): void {
                expect($exception->step)
                    ->toBe($step)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });
    })->with([
        'nonzero' => [apt_result('secret stdout', exitCode: 13, stderr: 'secret stderr'), 'installed-version'],
        'truncated' => [apt_result('secret stdout', stderr: 'secret stderr', truncated: true), 'ssh'],
        'missing version' => [apt_result("install ok installed\n"), 'installed-version'],
        'duplicate version' => [apt_result("install ok installed\n1.0.0\n2.0.0\n"), 'installed-version'],
        'malformed status' => [apt_result("deinstall ok config-files\n1.0.0\n"), 'installed-version'],
        'control bearing' => [apt_result("install ok installed\n1.0\0hidden\n"), 'installed-version'],
        'oversized' => [apt_result("install ok installed\n".str_repeat('1', times: 256)."\n"), 'installed-version'],
    ]);

    it('fails closed and sanitizes failed mutations', function (
        Closure $operation,
        array $arguments,
        string $step,
    ): void {
        $stdoutSentinel = 'secret mutation stdout';
        $stderrSentinel = 'secret mutation stderr';
        [$manager, $ssh] = apt_tool_manager([
            apt_result($stdoutSentinel, exitCode: 14, stderr: $stderrSentinel),
        ]);

        expect(fn () => $operation($manager, apt_tool_node()))
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
            static fn (AptToolManager $manager, Node $node) => $manager->install($node, 'jq'),
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            'install',
        ],
        'update' => [
            static fn (AptToolManager $manager, Node $node) => $manager->update($node, 'jq'),
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            'update',
        ],
        'removal plan' => [
            static fn (AptToolManager $manager, Node $node) => $manager->planRemoval($node, 'jq'),
            ['apt-get', '--simulate', 'remove', '--', 'jq'],
            'removal-plan',
        ],
        'remove' => [
            static fn (AptToolManager $manager, Node $node) => $manager->remove($node, 'jq'),
            ['sudo', 'apt-get', 'remove', '--yes', '--', 'jq'],
            'remove',
        ],
    ]);

    it('returns every unique planned removal and strips only the requested package architecture', function (): void {
        [$manager] = apt_tool_manager([
            apt_result(<<<'OUTPUT'
                Remv jq:amd64 [1.7.1-3ubuntu0.26.04.1]
                Remv libonig5:amd64 [6.9.9-1]
                Remv jq:amd64 [1.7.1-3ubuntu0.26.04.1]
                OUTPUT),
        ]);

        $plan = $manager->planRemoval(apt_tool_node(), 'jq');

        expect($plan->packages)->toBe(['jq', 'libonig5:amd64'])->and($plan->removesOnly('jq'))->toBeFalse();
    });

    it('returns an empty unsafe plan when APT schedules no removal', function (): void {
        [$manager] = apt_tool_manager([
            apt_result("Reading package lists...\nBuilding dependency tree...\n"),
        ]);

        $plan = $manager->planRemoval(apt_tool_node(), 'jq');

        expect($plan->packages)->toBeEmpty()->and($plan->removesOnly('jq'))->toBeFalse();
    });

    it('rejects malformed planned-removal records', function (string $output): void {
        [$manager] = apt_tool_manager([apt_result($output)]);

        expect(fn () => $manager->planRemoval(apt_tool_node(), 'jq'))
            ->toThrow(ToolManagerException::class, 'malformed');
    })->with([
        'missing package' => ["Remv\n"],
        'invalid package token' => ["Remv jq* [1.0]\n"],
        'missing record separator' => ["Remv jq[1.0]\n"],
    ]);

    it('remove executes exactly one removal command without replanning', function (): void {
        [$manager, $ssh] = apt_tool_manager([apt_result()]);

        $manager->remove(apt_tool_node(), 'jq');

        expect($ssh->arguments())->toBe([
            ['sudo', 'apt-get', 'remove', '--yes', '--', 'jq'],
        ]);
    });
});

/**
 * @param list<CommandResult> $results
 * @return array{AptToolManager, ToolManagerFakeSshExecutor}
 */
function apt_tool_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    $runner = new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: apt_tool_keys(),
        knownHosts: apt_tool_known_hosts(),
    );

    return [
        new AptToolManager(
            commands: $runner,
            versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
        ),
        $ssh,
    ];
}

function apt_tool_node(string $platform = 'linux'): Node
{
    return new Node([
        'name' => 'apt-tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.8.0.43',
    ]);
}

function apt_result(
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

function apt_tool_keys(): SshKeyProvider
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

function apt_tool_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
