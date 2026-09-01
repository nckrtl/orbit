<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\FirewallRule;
use App\Models\Node;

it('defines the firewall inspection contract', function (): void {
    expect(interface_exists(FirewallInspector::class))->toBeTrue();
});

it('inspects an exact active rule with the fixed read-only command', function (): void {
    $ssh = new InspectorFakeSsh(
        new CommandResult(
            0,
            "Status: active\n\nTo                         Action      From\n[ 1] 443/tcp                ALLOW IN    Anywhere                   # orbit:node:7:firewall:web\n[ 2] 443/tcp (v6)           ALLOW IN    Anywhere (v6)              # orbit:node:7:firewall:web\n",
            '',
            1,
            false,
        ),
    );

    $result = inspector($ssh)->inspect(inspector_target());

    expect($result->backend)
        ->toBe(FirewallBackendStatus::Active)
        ->and($result->rule)
        ->toBe(FirewallRuleInspectionStatus::Exact)
        ->and($ssh->arguments)
        ->toBe([['sudo', 'ufw', 'status', 'numbered']])
        ->and($ssh->connections[0])
        ->toEqual(new SshConnection('10.44.0.3', 'nckrtl', 22, '/key', '/known', commandTimeout: 30.0));
});

it('inspects a non-persisted Metrics target from its exact typed shape', function (): void {
    $ssh = new InspectorFakeSsh(new CommandResult(
        0,
        "Status: active\n\nTo Action From\n[ 1] 10.44.0.4 9100/tcp on orbit ALLOW IN 10.44.0.3 # orbit:metrics-node-exporter\n",
        '',
        1,
        false,
    ));
    $node = new Node(['platform' => 'linux', 'user' => 'nckrtl', 'wireguard_ip' => '10.44.0.4']);
    $node->id = 8;
    $target = new FirewallInspectionTarget(
        node: $node,
        shape: new FirewallInspectionShape(
            comment: 'orbit:metrics-node-exporter',
            action: 'allow',
            direction: 'in',
            source: '10.44.0.3',
            destination: '10.44.0.4',
            port: '9100',
            protocol: 'tcp',
            inInterface: 'orbit',
            outInterface: null,
            family: 'v4',
        ),
        resourceId: 'orbit:metrics-node-exporter',
        resourceName: 'Metrics node exporter',
    );

    $result = inspector($ssh)->inspect($target);

    expect($result->backend)
        ->toBe(FirewallBackendStatus::Active)
        ->and($result->rule)
        ->toBe(FirewallRuleInspectionStatus::Exact)
        ->and($ssh->arguments)
        ->toBe([['sudo', 'ufw', 'status', 'numbered']]);
});

it('does not mutate persisted firewall rule attributes', function (): void {
    $rule = inspector_rule();
    $before = $rule->getAttributes();
    inspector(new InspectorFakeSsh(new CommandResult(0, "Status: inactive\n", '', 1, false)))
        ->inspect(FirewallInspectionTarget::fromRule($rule));
    expect($rule->getAttributes())->toBe($before);
});

it('fails closed for a timed out command result', function (): void {
    $ssh = new InspectorFakeSsh(new CommandResult(124, '', 'timeout secret', 30_000, true));
    expect(fn (): mixed => inspector($ssh)->inspect(inspector_target()))->toThrow(DoctorInspectionException::class, '');
});

it('fails closed for truncated successful output', function (): void {
    $ssh = new InspectorFakeSsh(new CommandResult(0, "Status: active\n", '', 1, true));
    expect(fn (): mixed => inspector($ssh)->inspect(inspector_target()))->toThrow(DoctorInspectionException::class, '');
});

it('maps absent backend and rejects malformed output without leaking details', function (): void {
    $ssh = new InspectorFakeSsh(new CommandResult(0, "Status: absent\nsecret-config\n", 'secret-stderr', 1, false));

    $result = inspector($ssh)->inspect(inspector_target());

    expect($result->backend)
        ->toBe(FirewallBackendStatus::Absent)
        ->and($result->rule)
        ->toBe(FirewallRuleInspectionStatus::Missing);

    $ssh->result = new CommandResult(0, 'secret-output', 'secret-error', 1, false);
    expect(fn (): mixed => inspector($ssh)->inspect(inspector_target()))
        ->toThrow(DoctorInspectionException::class, '');
});

it('maps missing, drift, and inactive observations', function (
    string $output,
    FirewallBackendStatus $backend,
    FirewallRuleInspectionStatus $status,
): void {
    $result = inspector(new InspectorFakeSsh(new CommandResult(0, $output, '', 1, false)))
        ->inspect(inspector_target());
    expect($result->backend)->toBe($backend)->and($result->rule)->toBe($status);
})->with([
    ["Status: active\n\nTo Action From\n", FirewallBackendStatus::Active, FirewallRuleInspectionStatus::Missing],
    [
        "Status: active\n\nTo Action From\n[ 1] 443/tcp ALLOW IN 192.0.2.0/24 # orbit:node:7:firewall:web\n[ 2] 443/tcp (v6) ALLOW IN 192.0.2.0/24 (v6) # orbit:node:7:firewall:web\n",
        FirewallBackendStatus::Active,
        FirewallRuleInspectionStatus::Drift,
    ],
    ["Status: inactive\n", FirewallBackendStatus::Inactive, FirewallRuleInspectionStatus::Missing],
]);

it('fails closed for command errors and transport timeouts without redaction leaks', function (): void {
    $ssh = new InspectorFakeSsh(new CommandResult(1, 'secret-output', 'secret-stderr', 1, false));
    expect(fn (): mixed => inspector($ssh)->inspect(inspector_target()))->toThrow(DoctorInspectionException::class, '');
    $ssh->throws = true;
    expect(fn (): mixed => inspector($ssh)->inspect(inspector_target()))->toThrow(DoctorInspectionException::class, '');
    expect($ssh->arguments)->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

function inspector_rule(): FirewallRule
{
    $node = new Node(['platform' => 'linux', 'user' => 'nckrtl', 'wireguard_ip' => '10.44.0.3']);
    $node->id = 7;
    $rule = new FirewallRule([
        'node_id' => 7,
        'name' => 'web',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
    ]);
    $rule->id = 11;
    $rule->setRelation('node', $node);

    return $rule;
}

function inspector_target(): FirewallInspectionTarget
{
    return FirewallInspectionTarget::fromRule(inspector_rule());
}

function inspector(InspectorFakeSsh $ssh): NativeUfwFirewallInspector
{
    return new NativeUfwFirewallInspector($ssh, new InspectorFakeKeys, new InspectorFakeHosts);
}

final class InspectorFakeSsh implements SshExecutor
{
    /** @var list<list<string>> */
    public array $arguments = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct(
        public CommandResult $result,
        public bool $throws = false,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->arguments[] = $command->arguments;
        if ($this->throws) {
            throw new RuntimeException('secret timeout');
        }

        return $this->result;
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps key isolation explicit. */
final readonly class InspectorFakeKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/key';
    }

    public function publicKey(): string
    {
        return 'key';
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps host isolation explicit. */
final readonly class InspectorFakeHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/known';
    }

    public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $hostKey): void {}
}
