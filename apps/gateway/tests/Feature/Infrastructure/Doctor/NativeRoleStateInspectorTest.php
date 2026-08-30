<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('inspects each role with exact package service and firewall requirements', function (
    RoleName $role,
    array $packages,
    array $services,
    array $comments,
): void {
    $ssh = new RoleInspectorSshExecutor([
        role_inspector_result("1\n"),
        role_inspector_result("1\n"),
        role_inspector_result(role_inspector_ufw($comments)),
    ]);

    $state = role_state_inspector($ssh)->inspect(role_inspector_assignment($role));

    expect($state->packagesPresent)
        ->toBeTrue()
        ->and($state->servicesActive)
        ->toBeTrue()
        ->and($state->firewallProjectionMatches)
        ->toBeTrue()
        ->and($ssh->calls)
        ->toHaveCount(3)
        ->and($ssh->calls[0]['command']->arguments)
        ->toBe(['bash', '-seu', '--', ...$packages])
        ->and($ssh->calls[1]['command']->arguments)
        ->toBe(['bash', '-seu', '--', ...$services])
        ->and($ssh->calls[2]['command']->arguments)
        ->toBe(['sudo', 'ufw', 'status', 'numbered'])
        ->and($ssh->calls[0]['command']->input)
        ->toContain('dpkg-query')
        ->not->toContain('apt-get', 'install ', 'remove ', 'update ')->and($ssh->calls[1]['command']->input)->toContain(
            'systemctl is-active',
        )
        ->not->toContain('systemctl start', 'systemctl stop', 'systemctl restart')->and(array_column(
            $ssh->calls,
            'connection',
        ))->each(fn ($connection) => $connection->toEqual(
            new SshConnection('10.44.0.2', 'nckrtl', 22, '/key', '/known', commandTimeout: 30.0),
        ));
})->with([
    'gateway' => [
        RoleName::Gateway,
        ['ca-certificates'],
        ['caddy', 'php8.5-fpm'],
        ['orbit:gateway-https'],
    ],
    'VPN' => [
        RoleName::Vpn,
        ['dnsmasq', 'openssl'],
        ['wg-quick@orbit', 'dnsmasq'],
        ['orbit:vpn-ssh'],
    ],
    'app development' => [
        RoleName::AppDev,
        ['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'],
        ['caddy', 'docker'],
        [
            'orbit:app-dev-http',
            'orbit:app-dev-https',
            'orbit:app-dev-direct-http',
            'orbit:app-dev-direct-https',
        ],
    ],
    'app production' => [
        RoleName::AppProd,
        ['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'],
        ['caddy', 'docker'],
        ['orbit:app-prod-http', 'orbit:app-prod-https'],
    ],
]);

it('returns independent false projections for one missing requirement', function (
    string $packageState,
    string $serviceState,
    array $firewallComments,
    array $expected,
): void {
    $ssh = new RoleInspectorSshExecutor([
        role_inspector_result($packageState),
        role_inspector_result($serviceState),
        role_inspector_result(role_inspector_ufw($firewallComments)),
    ]);

    $state = role_state_inspector($ssh)->inspect(role_inspector_assignment(RoleName::AppDev));

    expect([
        $state->packagesPresent,
        $state->servicesActive,
        $state->firewallProjectionMatches,
    ])->toBe($expected);
})->with([
    'missing package' => [
        "0\n",
        "1\n",
        [
            'orbit:app-dev-http',
            'orbit:app-dev-https',
            'orbit:app-dev-direct-http',
            'orbit:app-dev-direct-https',
        ],
        [false, true, true],
    ],
    'inactive service' => [
        "1\n",
        "0\n",
        [
            'orbit:app-dev-http',
            'orbit:app-dev-https',
            'orbit:app-dev-direct-http',
            'orbit:app-dev-direct-https',
        ],
        [true, false, true],
    ],
    'missing firewall rule' => ["1\n", "1\n", ['orbit:app-dev-http'], [true, true, false]],
]);

it('fails closed for command failure timeout truncation and malformed output', function (
    array $results,
): void {
    $ssh = new RoleInspectorSshExecutor($results);

    expect(fn (): mixed => role_state_inspector($ssh)->inspect(role_inspector_assignment(RoleName::Gateway)))
        ->toThrow(DoctorInspectionException::class, '');
})->with([
    'command failure' => [[new CommandResult(1, 'secret-output', 'secret-error', 1, false)]],
    'timeout' => [[new CommandResult(124, '', 'timeout secret', 30_000, false)]],
    'truncation' => [[new CommandResult(0, "1\n", '', 1, true)]],
    'malformed tuple' => [[role_inspector_result("1\nsecret\n")]],
]);

it('maps transport failures to an empty typed exception and keeps persisted attributes unchanged', function (): void {
    $assignment = role_inspector_assignment(RoleName::Gateway);
    $before = $assignment->getAttributes();
    $ssh = new RoleInspectorSshExecutor([]);
    $ssh->throws = true;

    expect(fn (): mixed => role_state_inspector($ssh)->inspect($assignment))
        ->toThrow(DoctorInspectionException::class, '')
        ->and($assignment->getAttributes())
        ->toBe($before);
});

it('accepts only a complete healthy Docker CE stack as the Docker prerequisite', function (): void {
    $ssh = new RoleInspectorSshExecutor([
        role_inspector_result("1\n"),
        role_inspector_result("1\n"),
        role_inspector_result(role_inspector_ufw([
            'orbit:app-dev-http',
            'orbit:app-dev-https',
            'orbit:app-dev-direct-http',
            'orbit:app-dev-direct-https',
        ])),
    ]);

    role_state_inspector($ssh)->inspect(role_inspector_assignment(RoleName::AppDev));
    $script = $ssh->calls[0]['command']->input;
    $root = sys_get_temp_dir().'/orbit-doctor-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/bin", 0o755, true);
    $filesystem->put(
        "{$root}/bin/dpkg-query",
        "#!/bin/sh\neval package=\\\${\$#}\n[ \"\$package\" = docker.io ] && exit 1\n[ \"\$package\" = git ] && [ \"\$DOCTOR_STATE\" = missing-git ] && exit 1\n[ \"\$DOCTOR_STATE\" = healthy ] || [ \"\$DOCTOR_STATE\" = missing-git ] || [ \"\$DOCTOR_STATE\" != \"missing-\$package\" ] || exit 1\ncase \"\$*\" in *db:Status-Abbrev*) printf 'ii \\n' ;; *) printf 'install ok installed\\n' ;; esac\n",
    );
    $filesystem->put(
        "{$root}/bin/systemctl",
        "#!/bin/sh\n[ \"\$DOCTOR_STATE\" != inactive ]\n",
    );
    chmod("{$root}/bin/dpkg-query", 0o755);
    chmod("{$root}/bin/systemctl", 0o755);
    $docker = "{$root}/docker";
    $filesystem->put($docker, "#!/bin/sh\nexit 0\n");
    chmod($docker, 0o755);
    try {
        foreach ([
            'healthy',
            'missing-docker-ce',
            'missing-docker-ce-cli',
            'missing-containerd.io',
            'missing-binary',
            'inactive',
            'missing-git',
        ] as $state) {
            chmod($docker, $state === 'missing-binary' ? 0o644 : 0o755);
            $process = new Process(['bash', '-seu', '--', 'acl', 'docker.io', 'git']);
            $process->setEnv(['PATH' => "{$root}/bin:".getenv('PATH'), 'DOCTOR_STATE' => $state]);
            $process->setInput(str_replace('/usr/bin/docker', $docker, $script));
            $process->run();
            expect($process->getOutput())->toBe($state === 'healthy' ? "1\n" : "0\n");
        }
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

function role_state_inspector(RoleInspectorSshExecutor $ssh): NativeRoleStateInspector
{
    return new NativeRoleStateInspector(
        $ssh,
        new RoleInspectorKeys,
        new RoleInspectorKnownHosts,
        new NodeBootstrapPackageCatalog,
        new NodeRoleServiceCatalog,
        new NodeFirewallRuleCatalog,
        new CommandDeadline,
    );
}

function role_inspector_assignment(RoleName $role): NodeRole
{
    $node = new Node([
        'name' => 'role-inspector',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'public_ssh_port' => 2022,
        'user' => 'nckrtl',
        'wireguard_address' => '10.44.0.2',
    ]);
    $node->id = 7;
    $assignment = new NodeRole([
        'node_id' => 7,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);
    $assignment->id = 11;
    $assignment->setRelation('node', $node);

    return $assignment;
}

function role_inspector_result(string $stdout): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

/** @param list<string> $comments */
function role_inspector_ufw(array $comments): string
{
    $lines = ['Status: active'];
    $number = 1;

    foreach ($comments as $comment) {
        $targets = match ($comment) {
            'orbit:vpn-ssh' => ['10.44.0.2 22/tcp on orbit'],
            'orbit:gateway-https' => ['443/tcp on orbit', '443/tcp (v6) on orbit'],
            'orbit:app-dev-http' => ['10.44.0.2 80/tcp on orbit'],
            'orbit:app-dev-https' => ['10.44.0.2 443/tcp on orbit'],
            'orbit:app-dev-direct-http' => ['80/tcp', '80/tcp (v6)'],
            'orbit:app-dev-direct-https' => ['443/tcp', '443/tcp (v6)'],
            'orbit:app-prod-http' => ['80/tcp', '80/tcp (v6)'],
            'orbit:app-prod-https' => ['443/tcp', '443/tcp (v6)'],
            default => throw new LogicException("Unknown role inspector rule [{$comment}]."),
        };

        foreach ($targets as $target) {
            $v6 = str_contains($target, '(v6)') ? ' (v6)' : '';
            $lines[] = "[ {$number}] {$target} ALLOW IN Anywhere{$v6} # {$comment}";
            $number++;
        }
    }

    return implode("\n", $lines)."\n";
}

final class RoleInspectorSshExecutor implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public bool $throws = false;

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];

        if ($this->throws) {
            throw new RuntimeException('secret transport detail');
        }

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected inspector call.');
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps key material isolated. */
final readonly class RoleInspectorKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/key';
    }

    public function publicKey(): string
    {
        return 'public';
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps host state isolated. */
final readonly class RoleInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/known';
    }

    public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
}
