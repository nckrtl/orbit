<?php

declare(strict_types=1);

use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

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

describe('Caddy package source', function (): void {
    it('publishes the source only for the roles that serve through Caddy', function (RoleName $role, bool $needed): void {
        $command = new NodeRolePrerequisiteCommandFactory()->caddySource(new Node, $role);

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
        'ingress' => [RoleName::Ingress, false],
    ]);

    it('passes the pinned source, key digest, fingerprint, and version floor as fixed argv', function (): void {
        $command = new NodeRolePrerequisiteCommandFactory()->caddySource(new Node, RoleName::AppDev);

        expect($command?->arguments)->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            'https://dl.cloudsmith.io/public/caddy/stable/deb/debian',
            'any-version',
            'main',
            'https://dl.cloudsmith.io/public/caddy/stable/gpg.key',
            '/usr/share/keyrings/orbit-caddy.gpg',
            '/etc/apt/sources.list.d/orbit-caddy.sources',
            '783dfee04b19e851a928cd87b34710213ebbe7628f98d9f34595ab83be578c00',
            '65760C51EDEA2017CEA2CA15155B6D79CA56EA34',
            '2.9.0',
        ]);
    });

    it('verifies the key, writes a deb822 source, pins the candidate origin, and enforces the floor', function (): void {
        $script = CaddyPackageSourceProgram::render();

        expect($script)->toContain(
            '[ ! -e "$managed_path" ] && [ ! -L "$managed_path" ]',
            'mktemp -d',
            'install -d -m 0700 -- "$gnupg_home"',
            "curl --fail --silent --show-error --location --proto '=https' --tlsv1.2",
            'sha256sum --check --status',
            'GNUPGHOME="$gnupg_home" gpg --batch --with-colons --show-keys',
            'gpg --batch --dearmor --output',
            'Types: deb',
            'Signed-By: %s',
            'trap restore_caddy_source EXIT',
            'install -m 0644 -o root -g root',
            'apt-get -o DPkg::Lock::Timeout=300 update',
            'apt-cache policy -- caddy',
            'apt-cache madison -- caddy',
            'expected_origin="$source_uri $suite/$component $(dpkg --print-architecture) Packages"',
            '-o Dpkg::Options::=--force-confold',
            'dpkg --compare-versions "$installed_version" ge "$minimum_version"',
        )->not->toContain('apt-key', 'add-apt-repository');
    });

    it('never removes a package while installing Caddy', function (): void {
        expect(CaddyPackageSourceProgram::render())
            ->toContain('install --yes --no-install-recommends --no-remove -- caddy')
            ->not->toContain(' remove ', ' purge ', ' autoremove ');
    });

    it('restores the previous keyring and source when the step fails after publishing', function (): void {
        $script = CaddyPackageSourceProgram::render();
        $publication = mb_strpos($script, 'install -m 0644 -o root -g root -- "$keyring_body"');
        $rollback = mb_strpos($script, 'trap restore_caddy_source EXIT');

        expect($rollback)->toBeInt()
            ->and($publication)->toBeInt()->toBeGreaterThan($rollback)
            ->and($script)->toContain(
                'if [ "$status" -ne 0 ] && [ "$published" -eq 1 ]; then',
                'install -m 0644 -o root -g root -- "$key_backup" "$keyring_path"',
                'install -m 0644 -o root -g root -- "$source_backup" "$source_path"',
            );
    });
});
