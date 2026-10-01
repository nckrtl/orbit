<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolOperation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerDryRunVersionParser;
use App\Infrastructure\Tools\ComposerInstalledInventoryParser;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use Tests\Support\ToolManagerFakeSshExecutor;

describe('tool manager dpkg lock', function (): void {
    it('passes a 300 second dpkg lock timeout on every Homebrew prerequisite apt-get command', function (): void {
        [$manager, $ssh] = apt_lock_homebrew([apt_lock_result()]);

        $manager->materialize(apt_lock_node());

        expect(apt_lock_lines($ssh->commands[0]->input ?? ''))->toBe([
            'apt-get -o DPkg::Lock::Timeout=300 update',
            'apt-get -o DPkg::Lock::Timeout=300 install --yes --no-install-recommends --no-remove -- build-essential procps curl file git ca-certificates',
        ]);
        apt_lock_expect_within_command_budget($ssh);
    });

    it('passes a 300 second dpkg lock timeout on every Composer prerequisite apt-get command', function (): void {
        [$manager, $ssh] = apt_lock_composer([apt_lock_result()]);

        $manager->materialize(apt_lock_node());

        expect(apt_lock_lines($ssh->commands[0]->input ?? ''))->toBe([
            'apt-get -o DPkg::Lock::Timeout=300 update',
            'apt-get -o DPkg::Lock::Timeout=300 install --yes --no-install-recommends --no-remove -- composer git unzip',
        ]);
        apt_lock_expect_within_command_budget($ssh);
    });

    it('passes a 300 second dpkg lock timeout on apt install, update, and remove', function (): void {
        [$manager, $ssh] = apt_lock_apt([
            apt_lock_result(),
            apt_lock_result(),
            apt_lock_result(),
        ]);
        $node = apt_lock_node();

        $manager->install($node, 'jq');
        $manager->update($node, 'jq');
        $manager->remove($node, 'jq');

        expect($ssh->arguments())->toBe([
            ['sudo', 'apt-get', '-o', 'DPkg::Lock::Timeout=300', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            ['sudo', 'apt-get', '-o', 'DPkg::Lock::Timeout=300', 'install', '--yes', '--no-install-recommends', '--', 'jq'],
            ['sudo', 'apt-get', '-o', 'DPkg::Lock::Timeout=300', 'remove', '--yes', '--', 'jq'],
        ]);
        apt_lock_expect_within_command_budget($ssh);
    });

    it('leaves apt probes and simulated removal without a dpkg lock timeout', function (): void {
        [$manager, $ssh] = apt_lock_apt([
            apt_lock_result("apt 3.0.3 (amd64)\n"),
            apt_lock_result("jq:\n  Candidate: 1.7.1-3ubuntu0.26.04.1\n"),
            apt_lock_result("Remv jq [1.7.1-3ubuntu0.26.04.1]\n"),
        ]);
        $node = apt_lock_node();

        $manager->managerVersion($node);
        $manager->candidateVersion($node, 'jq', ToolOperation::Install);
        $manager->planRemoval($node, 'jq');

        expect($ssh->arguments())->toBe([
            ['apt-get', '--version'],
            ['apt-cache', 'policy', '--', 'jq'],
            ['apt-get', '--simulate', 'remove', '--', 'jq'],
        ]);
        expect(json_encode($ssh->arguments()))->not->toContain('DPkg::Lock::Timeout');
    });
});

function apt_lock_expect_within_command_budget(ToolManagerFakeSshExecutor $ssh): void
{
    expect($ssh->connections)->not->toBeEmpty();
    expect($ssh->commands)->toHaveCount(count($ssh->connections));

    foreach ($ssh->connections as $index => $connection) {
        $command = $ssh->commands[$index];
        $text = $command->input ?? implode(' ', $command->arguments);
        $matches = [];

        expect(preg_match_all('/DPkg::Lock::Timeout=(\d+)/', $text, $matches))->toBeGreaterThan(0);

        foreach ($matches[1] as $seconds) {
            expect((int) $seconds)->toBeLessThan($connection->commandTimeout);
        }
    }
}

/** @return list<string> */
function apt_lock_lines(string $program): array
{
    $lines = preg_split('/\R/', $program);

    return array_values(array_filter(
        is_array($lines) ? $lines : [],
        static fn (string $line): bool => preg_match('/\bapt-get\b/', $line) === 1,
    ));
}

/**
 * @param  list<CommandResult>  $results
 * @return array{HomebrewToolManager, ToolManagerFakeSshExecutor}
 */
function apt_lock_homebrew(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new HomebrewToolManager(
            commands: apt_lock_runner($ssh),
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

/**
 * @param  list<CommandResult>  $results
 * @return array{ComposerToolManager, ToolManagerFakeSshExecutor}
 */
function apt_lock_composer(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new ComposerToolManager(
            commands: apt_lock_runner($ssh),
            parser: new ComposerDryRunVersionParser,
            inventory: new ComposerInstalledInventoryParser,
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

/**
 * @param  list<CommandResult>  $results
 * @return array{AptToolManager, ToolManagerFakeSshExecutor}
 */
function apt_lock_apt(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new AptToolManager(
            commands: apt_lock_runner($ssh),
            versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
        ),
        $ssh,
    ];
}

function apt_lock_runner(ToolManagerFakeSshExecutor $ssh): RemoteToolCommandRunner
{
    return new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: apt_lock_keys(),
        knownHosts: apt_lock_known_hosts(),
    );
}

function apt_lock_node(): Node
{
    return new Node([
        'name' => 'apt-lock-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.46',
    ]);
}

function apt_lock_result(string $stdout = ''): CommandResult
{
    return new CommandResult(
        exitCode: 0,
        stdout: $stdout,
        stderr: '',
        durationMs: 10,
        truncated: false,
    );
}

function apt_lock_keys(): SshKeyProvider
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

function apt_lock_known_hosts(): KnownHostsStore
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
