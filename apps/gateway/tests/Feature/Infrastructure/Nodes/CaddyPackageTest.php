<?php

declare(strict_types=1);

use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Tests\Support\CaddyPackageHarness;

describe('Caddy release floor', function (): void {
    it('reads the release from either build of `caddy version`', function (string $output, ?string $release): void {
        expect(CaddyRelease::reported($output))->toBe($release);
    })->with([
        'Ubuntu archive build' => ["2.6.2\n", '2.6.2'],
        'Caddy project build' => ["v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=\n", '2.11.4'],
        'two-part release' => ['v2.8', '2.8.0'],
        'no output' => ['', null],
        'not a version' => ["caddy: command not found\n", null],
    ]);

    it('refuses every release below the one Orbit renders against', function (string $output, bool $supported): void {
        expect(CaddyRelease::supports($output))->toBe($supported);
    })->with([
        'the archive build that cannot read log_skip' => ["2.6.2\n", false],
        'the release that cannot read tls force_automate' => ["2.8.4\n", false],
        'the floor itself' => ["2.9.0\n", true],
        'the current stable' => ["v2.11.4 h1:abc\n", true],
        'unreadable output' => ['nonsense', false],
    ]);

    it('states the floor as a constraint the Tool version helper understands', function (): void {
        expect(CaddyRelease::constraint())->toBe('>=2.9.0')
            ->and(CaddyRelease::MINIMUM)->toBe('2.9.0');
    });
});

describe('Caddy package', function (): void {
    it('installs the package only for the roles that serve through Caddy', function (RoleName $role, bool $needed): void {
        $command = new NodeRolePrerequisiteCommandFactory()->caddyPackage(new Node, $role);

        expect($command instanceof RemoteCommand)->toBe($needed);
    })->with([
        'router' => [RoleName::Router, true],
        'app development' => [RoleName::AppDev, true],
        'app production' => [RoleName::AppProd, true],
        'websocket' => [RoleName::WebSocket, true],
        'analytics' => [RoleName::Analytics, true],
        'gateway' => [RoleName::Gateway, true],
        'VPN' => [RoleName::Vpn, false],
        'database' => [RoleName::Database, false],
        'metrics' => [RoleName::Metrics, false],
        'ingress' => [RoleName::Ingress, true],
    ]);

    it('passes the pinned release, digests, version floor, kernel setting, and old source as fixed argv', function (): void {
        $command = new NodeRolePrerequisiteCommandFactory()->caddyPackage(new Node, RoleName::AppDev);

        expect($command?->arguments)->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            '2.11.7',
            'https://github.com/caddyserver/caddy/releases/download/v2.11.7',
            '47e8351c2317b427af14a103e763ca1118a3d2396a88b4c0669cdec9c4a68a957690194e2423a1633f53135741c33a41bdac2b55515b7d0f7adc8b733add50d9',
            'ac32f03f0eea04021f2d4e5d89bfd01b84ead115f2eba9d2a0d06447e86d4af932899e56f090993a566d0925dbd1063002929b395541a8c5f5e51d7039f7375a',
            '2.9.0',
            '/etc/sysctl.d/60-orbit-caddy.conf',
            'net.ipv4.tcp_migrate_req = 1',
            '/usr/share/keyrings/orbit-caddy.gpg',
            '/etc/apt/sources.list.d/orbit-caddy.sources',
        ]);
    });

    it('never refreshes apt sources, removes a package, or trusts an unpinned download', function (): void {
        $script = CaddyPackageSourceProgram::render();

        expect($script)->toContain(
            "curl --fail --silent --show-error --location --proto '=https' --tlsv1.2",
            'sha512sum --check --status',
            '-o Dpkg::Options::=--force-confold',
            'install --yes --no-install-recommends --no-remove -- "$package_path"',
            'dpkg --compare-versions "$current_version" ge "$minimum_version"',
        );
        expect($script)->not->toContain('apt-get -o DPkg::Lock::Timeout=300 update');
        expect($script)->not->toContain('dl.cloudsmith.io');
        expect($script)->not->toContain(' remove ');
        expect($script)->not->toContain(' purge ');
        expect($script)->not->toContain(' autoremove ');
    });
});

describe('Caddy package program', function (): void {
    beforeEach(function (): void {
        $this->harness = new CaddyPackageHarness;
    });

    afterEach(function (): void {
        $this->harness->cleanup();
    });

    it('installs the pinned package when the Node has no Caddy', function (): void {
        [$exitCode, $output, , $calls] = $this->harness->run();

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("orbit-caddy-package-result=changed\n")
            ->and($calls)->toBe([
                'sysctl --quiet --load net.ipv4.tcp_migrate_req = 1',
                'install 60-orbit-caddy.conf',
                'curl caddy_2.11.7_linux_amd64.deb',
                'apt-get install caddy_2.11.7_linux_amd64.deb',
            ]);
    });

    it('upgrades an archive Caddy below the floor', function (): void {
        $this->harness->caddy('2.6.2');

        [$exitCode, , , $calls] = $this->harness->run();

        expect($exitCode)->toBe(0)
            ->and($calls)->toContain('apt-get install caddy_2.11.7_linux_amd64.deb');
    });

    it('downloads the package for an arm64 Node', function (): void {
        [$exitCode, , , $calls] = $this->harness->run(architecture: 'arm64');

        expect($exitCode)->toBe(0)
            ->and($calls)->toContain('curl caddy_2.11.7_linux_arm64.deb', 'apt-get install caddy_2.11.7_linux_arm64.deb');
    });

    it('leaves a Caddy at the floor alone, so a converge never restarts it', function (string $version): void {
        $this->harness->caddy($version);
        $this->harness->run();

        [$exitCode, $output, , $calls] = $this->harness->run();

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("orbit-caddy-package-result=unchanged\n")
            ->and($calls)->toBe(['sysctl --quiet --load net.ipv4.tcp_migrate_req = 1']);
    })->with(['the floor' => '2.9.0', 'an older Cloudsmith build' => '2.11.4', 'a newer release' => '2.12.0']);

    it('deletes the Cloudsmith source and keyring Orbit used to publish', function (): void {
        $this->harness->caddy('2.11.4');
        $this->harness->run();
        file_put_contents($this->harness->legacySourcePath(), "Types: deb\nURIs: https://dl.cloudsmith.io/public/caddy/stable/deb/debian\n");
        file_put_contents($this->harness->legacyKeyringPath(), 'key');

        [$exitCode, $output, , $calls] = $this->harness->run();

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("orbit-caddy-package-result=changed\n")
            ->and($calls)->toBe(['sysctl --quiet --load net.ipv4.tcp_migrate_req = 1'])
            ->and(file_exists($this->harness->legacySourcePath()))->toBeFalse()
            ->and(file_exists($this->harness->legacyKeyringPath()))->toBeFalse();
    });

    it('refuses a package whose digest does not match the pin, before apt sees it', function (): void {
        [$exitCode, , $errors, $calls] = $this->harness->run(digestMatches: false);

        expect($exitCode)->toBe(1)
            ->and($errors)->toContain('The Caddy 2.11.7 package does not match the Orbit pin.')
            ->and($calls)->toContain('curl caddy_2.11.7_linux_amd64.deb')
            ->and($calls)->not->toContain('apt-get install caddy_2.11.7_linux_amd64.deb');
    });

    it('refuses an architecture without a pinned package', function (): void {
        [$exitCode, , $errors, $calls] = $this->harness->run(architecture: 'riscv64');

        expect($exitCode)->toBe(1)
            ->and($errors)->toContain('Orbit pins no Caddy package for the riscv64 architecture.')
            ->and($calls)->not->toContain('curl caddy_2.11.7_linux_riscv64.deb');
    });

    it('fails when the installed Caddy still reports a release below the floor', function (): void {
        [$exitCode, , $errors] = $this->harness->run(installs: '2.8.4');

        expect($exitCode)->toBe(1)
            ->and($errors)->toContain('Caddy 2.8.4 is older than the 2.9.0 Orbit renders against.');
    });
});

describe('Caddy reload kernel setting', function (): void {
    beforeEach(function (): void {
        $this->harness = new CaddyPackageHarness;
        $this->harness->caddy('2.11.7');
    });

    afterEach(function (): void {
        $this->harness->cleanup();
    });

    it('applies the setting from a candidate and then installs it', function (): void {
        [, , , $calls] = $this->harness->run();

        expect($calls)->toBe([
            'sysctl --quiet --load net.ipv4.tcp_migrate_req = 1',
            'install 60-orbit-caddy.conf',
        ])->and($this->harness->installedSetting())->toBe("net.ipv4.tcp_migrate_req = 1\n");
    });

    it('applies the setting again but leaves a matching file untouched', function (): void {
        $this->harness->run();

        [, , , $calls] = $this->harness->run();

        expect($calls)->toBe([
            'sysctl --quiet --load net.ipv4.tcp_migrate_req = 1',
        ])->and($this->harness->installedSetting())->toBe("net.ipv4.tcp_migrate_req = 1\n");
    });

    it('rewrites a file that no longer matches', function (): void {
        file_put_contents($this->harness->settingPath(), "net.ipv4.tcp_migrate_req = 0\n");

        [, , , $calls] = $this->harness->run();

        expect($calls)->toContain('install 60-orbit-caddy.conf')
            ->and($this->harness->installedSetting())->toBe("net.ipv4.tcp_migrate_req = 1\n");
    });

    it('fails without installing the file when the kernel refuses the setting', function (): void {
        [$exitCode, , , $calls] = $this->harness->run(kernelAccepts: false);

        expect($exitCode)->not->toBe(0)
            ->and($calls)->toBe(['sysctl --quiet --load net.ipv4.tcp_migrate_req = 1'])
            ->and($this->harness->installedSetting())->toBeNull();
    });

    it('refuses a live file with an unsafe mode before it changes anything', function (): void {
        file_put_contents($this->harness->settingPath(), "net.ipv4.tcp_migrate_req = 1\n");

        [$exitCode, , $errors, $calls] = $this->harness->run(liveFileSafe: false);

        expect($exitCode)->toBe(1)
            ->and($errors)->toContain('An Orbit Caddy file has unsafe ownership or mode.')
            ->and($calls)->toBe([]);
    });
});
