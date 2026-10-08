<?php

declare(strict_types=1);

use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\SshNodeCliInstaller;
use App\Models\Node;
use Tests\Support\Fleet\FakeCliReleaseCatalog;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;

function cliInstallNode(): Node
{
    FleetFixtures::node('gateway', [RoleName::Gateway]);

    return new Node(['name' => 'dev', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.2']);
}

function cliProfile(): string
{
    $address = Node::query()->where('name', 'gateway')->value('wireguard_ip');

    return NodeCliFootprint::configuration((string) $address);
}

function cliRelease(string $sha256 = ''): DesiredCliReleaseData
{
    return new FakeCliReleaseCatalog(sha256: $sha256 !== '' ? $sha256 : str_repeat('c', 64))->find(str_repeat('a', 40), new CliReleaseName(4681));
}

/** A Node whose canonical path reports `$state`, whose download hashes to `$downloaded`, and whose profile is `$profile`. */
function cliInstallSsh(string $state, string $downloaded = '', ?string $profile = null): ScriptedSshExecutor
{
    return new ScriptedSshExecutor()
        ->on('/^bash -seu -- \/usr\/local\/bin\/orbit$/', ScriptedSshExecutor::ok($state."\n"))
        ->on('/^sudo sha256sum -- \/usr\/local\/bin\/orbit-0\.4681\.0\.orbit-candidate$/', ScriptedSshExecutor::ok(($downloaded !== '' ? $downloaded : str_repeat('c', 64))."  candidate\n"))
        ->on('/^timeout 60 \/usr\/local\/bin\/orbit-0\.4681\.0\.orbit-candidate --version$/', ScriptedSshExecutor::ok("Orbit 0.4681.0\n"))
        ->on('/^sudo cat -- \/root\/\.orbit\/config\.json$/', $profile === null ? ScriptedSshExecutor::fail() : ScriptedSshExecutor::ok($profile))
        ->on('/^sudo test -L \/root\/\.orbit\/config\.json$/', ScriptedSshExecutor::fail());
}

describe('CLI install on a Node', function (): void {
    it('installs a missing CLI from the release, verified, as orbit-<version> behind the link, and writes root\'s profile', function (): void {
        $ssh = cliInstallSsh('missing');

        $installation = new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), cliRelease());
        $lines = $ssh->lines();

        expect($installation->outcome)->toBe(NodeCliInstallation::Installed)
            ->and($installation->version)->toBe('0.4681.0')
            ->and($installation->configured)->toBeTrue()
            ->and($lines)->toContain('sudo curl --fail --location --silent --show-error --proto =https --proto-redir =https --connect-timeout 20 --max-time 180 --output /usr/local/bin/orbit-0.4681.0.orbit-candidate -- https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-x86_64')
            ->and($lines)->toContain('sudo mv -fT -- /usr/local/bin/orbit-0.4681.0.orbit-candidate /usr/local/bin/orbit-0.4681.0')
            ->and($lines)->toContain('sudo bash -seu -- /usr/local/bin/orbit orbit-0.4681.0')
            ->and(array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'orbit-update-lock') || str_contains($line, '/run/lock/orbit-self-update.lock'))))->toHaveCount(2)
            ->and($lines)->toContain('sudo install -d -o root -g root -m 0700 /root/.orbit')
            ->and($lines)->toContain('sudo mv -fT -- /root/.orbit/config.json.orbit-candidate /root/.orbit/config.json');

        $gateway = Node::query()->where('name', 'gateway')->value('wireguard_ip');
        expect(json_decode(cliProfile(), true))->toBe([
            'active_gateway' => 'gateway',
            'gateways' => ['gateway' => ['url' => 'https://'.$gateway, 'ca_path' => '/etc/orbit/agent/ca.pem']],
        ]);
    });

    it('reads the path again under the update lock and installs nothing when a self-update got there first', function (): void {
        $inspections = 0;
        $ssh = new ScriptedSshExecutor()
            ->on('/^bash -seu -- \\/usr\\/local\\/bin\\/orbit$/', static function () use (&$inspections) {
                return ScriptedSshExecutor::ok(++$inspections === 1 ? "missing\n" : "present\n");
            })
            ->on('/^sudo cat -- \\/root\\/\\.orbit\\/config\\.json$/', ScriptedSshExecutor::fail())
            ->on('/^sudo test -L /', ScriptedSshExecutor::fail());

        $installation = new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), cliRelease());

        expect($inspections)->toBe(2)
            ->and($installation->outcome)->toBe(NodeCliInstallation::Present)
            ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, 'curl')))->toBe([]);
    });

    it('leaves a present CLI alone and writes nothing when the profile matches', function (): void {
        $node = cliInstallNode();
        $ssh = cliInstallSsh('present', profile: cliProfile());

        $installation = new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure($node, cliRelease());

        expect($installation->outcome)->toBe(NodeCliInstallation::Present)
            ->and($installation->changed())->toBeFalse()
            ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, 'curl') || str_contains($line, ' mv ')))->toBe([]);
    });

    it('replaces an older root-owned CLI that cannot update itself', function (): void {
        $ssh = cliInstallSsh('stale');

        $installation = new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), cliRelease());

        expect($installation->outcome)->toBe(NodeCliInstallation::Installed)
            ->and($ssh->lines())->toContain('sudo mv -fT -- /usr/local/bin/orbit-0.4681.0.orbit-candidate /usr/local/bin/orbit-0.4681.0');
    });

    it('installs nothing when the download fails its checksum', function (): void {
        $ssh = cliInstallSsh('missing', downloaded: str_repeat('d', 64));

        expect(fn () => new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), cliRelease()))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cli.checksum_mismatch'));

        expect($ssh->lines())->toContain('sudo rm -f -- /usr/local/bin/orbit-0.4681.0.orbit-candidate')
            ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, ' mv ')))->toBe([])
            ->and(array_filter($ssh->lines(), static fn (string $line): bool => str_contains($line, '/root/.orbit')))->toBe([]);
    });

    it('refuses a link or a file Orbit did not install', function (string $state): void {
        $ssh = cliInstallSsh($state);

        expect(fn () => new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), cliRelease()))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cli.foreign_binary'));

        expect($ssh->lines())->toHaveCount(1);
    })->with(['foreign']);

    it('cannot install a missing CLI before the release is published', function (): void {
        $ssh = cliInstallSsh('missing');

        expect(fn () => new SshNodeCliInstaller(FleetTestSsh::shell($ssh), new NodeUpdateLock(FleetTestSsh::shell($ssh)))->ensure(cliInstallNode(), DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::ReleaseMissing)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cli.release_unavailable'));
    });

    it('runs self-update as root with the Node profile', function (): void {
        expect(NodeCliFootprint::selfUpdateCommand())->toBe(['sudo', '/usr/local/bin/orbit', 'self-update', '--json']);
    });
});

describe('CLI link inspection', function (): void {
    /** Runs the inspect script against a directory that stands in for /usr/local/bin. */
    function cliInspect(Closure $arrange, bool $asElf = false): string
    {
        $directory = sys_get_temp_dir().'/orbit-cli-inspect-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $arrange($directory);
        $script = str_replace('"$(stat -c %u -- "$binary")" != 0', '"$(stat -c %u -- "$binary")" != "$(id -u)"', SshNodeCliInstaller::InspectScript);

        if ($asElf) {
            // Lets a shell script stand in for an Orbit ELF binary.
            $script = str_replace('if [ "$magic" != 7f454c46 ]', 'if false', $script);
        }
        $process = proc_open(['bash', '-seu', '--', $directory.'/orbit'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $output = trim((string) stream_get_contents($pipes[1]));
        proc_close($process);
        exec('rm -rf '.escapeshellarg($directory));

        return $output;
    }

    it('reads the link layout of orbit self-update', function (): void {
        $orbit = static function (string $path, string $version, bool $selfUpdate): void {
            file_put_contents($path, "#!/bin/sh\n[ \"\$1\" = --version ] && { echo 'Orbit {$version}'; exit 0; }\n[ \"\$1\" = self-update ] && exit ".($selfUpdate ? 0 : 1)."\nexit 1\n");
            chmod($path, 0755);
        };

        expect(cliInspect(static fn (string $dir) => null))->toBe('missing')
            ->and(cliInspect(static function (string $dir) use ($orbit): void {
                $orbit($dir.'/orbit-0.4681.0', '0.4681.0', true);
                symlink('orbit-0.4681.0', $dir.'/orbit');
            }, asElf: true))->toBe('present')
            ->and(cliInspect(static fn (string $dir) => $orbit($dir.'/orbit', '0.4681.0', true), asElf: true))->toBe('present')
            ->and(cliInspect(static fn (string $dir) => $orbit($dir.'/orbit', '12715ff83047c0e51f4b86618779b637b040471f', false), asElf: true))->toBe('stale')
            // A program that answers anything else is not an Orbit CLI, so it is never replaced.
            ->and(cliInspect(static fn (string $dir) => $orbit($dir.'/orbit', 'banana', false), asElf: true))->toBe('foreign')
            ->and(cliInspect(static fn (string $dir) => copy('/bin/false', $dir.'/orbit') && chmod($dir.'/orbit', 0755)))->toBe('foreign')
            ->and(cliInspect(static function (string $dir): void {
                symlink('/home/someone/orbit/apps/cli/orbit', $dir.'/orbit');
            }))->toBe('foreign')
            ->and(cliInspect(static function (string $dir) use ($orbit): void {
                mkdir($dir.'/sub');
                $orbit($dir.'/sub/orbit-0.4681.0', '0.4681.0', true);
                symlink('sub/orbit-0.4681.0', $dir.'/orbit');
            }, asElf: true))->toBe('foreign')
            ->and(cliInspect(static function (string $dir) use ($orbit): void {
                $orbit($dir.'/orbit-0.4681.0', '0.4681.0', true);
                symlink('../'.basename($dir).'/orbit-0.4681.0', $dir.'/orbit');
            }, asElf: true))->toBe('foreign');
    });

    it('refuses a script wrapper without running it', function (): void {
        $marker = sys_get_temp_dir().'/orbit-cli-wrapper-ran-'.bin2hex(random_bytes(4));

        $state = cliInspect(static function (string $dir) use ($marker): void {
            file_put_contents($dir.'/orbit', "#!/usr/bin/env bash\ntouch {$marker}\nexec /usr/bin/php /home/nckrtl/orbit/apps/cli/orbit \"\$@\"\n");
            chmod($dir.'/orbit', 0755);
        });

        expect($state)->toBe('foreign')
            ->and(file_exists($marker))->toBeFalse();
    });

    it('keeps a plain binary as orbit.orbit-previous when it switches the link', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cli-link-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/orbit', "legacy build\n");
        file_put_contents($directory.'/orbit.orbit-previous', "older backup\n");
        chmod($directory.'/orbit.orbit-previous', 0400);
        file_put_contents($directory.'/orbit-0.4681.0', "release\n");
        $process = proc_open(['bash', '-seu', '--', $directory.'/orbit', 'orbit-0.4681.0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], SshNodeCliInstaller::LinkScript);
        fclose($pipes[0]);
        proc_close($process);

        expect(readlink($directory.'/orbit'))->toBe('orbit-0.4681.0')
            ->and(file_get_contents($directory.'/orbit.orbit-previous'))->toBe("legacy build\n");

        exec('rm -rf '.escapeshellarg($directory));
    });
});
