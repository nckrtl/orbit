<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Models\Node;

it('covers exact package and service matrices', function (): void {
    $node = new Node(['public_ssh_port' => 22, 'wireguard_ip' => '10.0.0.1']);
    $p = new NodeBootstrapPackageCatalog;
    $s = new NodeRoleServiceCatalog;
    expect($p->forNode($node))
        ->toBe(['ca-certificates', 'curl', 'gnupg', 'libnss-resolve', 'openssh-client', 'sudo', 'ufw', 'wireguard'])
        ->and($p->forRole($node, RoleName::Gateway))
        ->toBe(['ca-certificates'])
        ->and($p->forRole($node, RoleName::Vpn))
        ->toBe(['dnsmasq', 'openssl'])
        ->and($p->forRole($node, RoleName::Router))
        ->toBe([])
        ->and($p->forRole($node, RoleName::AppDev))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'])
        ->and($p->forRole($node, RoleName::AppProd))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'])
        ->and($s->forRole(RoleName::Gateway))
        ->toBe(['caddy', 'php8.5-fpm'])
        ->and($s->forRole(RoleName::Vpn))
        ->toBe(['wg-quick@orbit', 'dnsmasq'])
        ->and($s->forRole(RoleName::Router))
        ->toBe([])
        ->and($s->forRole(RoleName::AppDev))
        ->toBe(['caddy', 'docker'])
        ->and($s->forRole(RoleName::AppProd))
        ->toBe(['caddy', 'docker']);
});

it('gives Router no package service or firewall projection', function (): void {
    $node = new Node(['public_ssh_port' => 22, 'wireguard_ip' => '10.0.0.1']);

    expect(new NodeBootstrapPackageCatalog()->forRole($node, RoleName::Router))
        ->toBe([])
        ->and(new NodeRoleServiceCatalog()->forRole(RoleName::Router))
        ->toBe([])
        ->and(new NodeFirewallRuleCatalog()->forRole($node, RoleName::Router))
        ->toBe([]);
});
it('returns typed exact firewall rules', function (): void {
    $rules = new NodeFirewallRuleCatalog()->forNode(new Node([
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.0.0.1',
    ]));
    expect($rules[0])
        ->toBeInstanceOf(UfwManagedRule::class)
        ->and($rules[0]->shape->comment)
        ->toBe('orbit:public-ssh-recovery');
});

it('keeps public app-production rules independent of a WireGuard address', function (): void {
    $rules = new NodeFirewallRuleCatalog()->forRole(
        new Node(['public_ssh_port' => 22, 'wireguard_ip' => null]),
        RoleName::AppProd,
    );

    expect(array_map(static fn (UfwManagedRule $rule): ?string => $rule->shape->destination, $rules))
        ->toBe(['any', 'any']);
});

it('matches the gateway writer exact shape independently of a WireGuard address', function (): void {
    $rules = new NodeFirewallRuleCatalog()->forRole(
        new Node(['public_ssh_port' => 22, 'wireguard_ip' => null]),
        RoleName::Gateway,
    );

    expect($rules)
        ->toHaveCount(1)
        ->and($rules[0]->shape)
        ->toEqual(new UfwRuleShape(
            comment: 'orbit:gateway-https',
            action: 'allow',
            direction: 'in',
            source: 'any',
            destination: 'any',
            port: '443',
            protocol: 'tcp',
            inInterface: 'orbit',
            outInterface: null,
            family: null,
        ));
});

it('returns Metrics firewall rules in exporter then publication catalog order', function (): void {
    $metrics = new Node(['name' => 'metrics', 'wireguard_ip' => '10.44.0.3']);
    $exporter = new Node(['name' => 'app-prod', 'wireguard_ip' => '10.44.0.4']);
    $catalog = new NodeFirewallRuleCatalog;
    $rules = [
        $catalog->metricsExporter($exporter, $metrics),
        $catalog->metricsGrafanaUpstream($metrics, '10.44.0.1'),
    ];

    expect(array_map(static fn (UfwManagedRule $rule): UfwRuleShape => $rule->shape, $rules))
        ->toEqual([
            new UfwRuleShape(
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
            new UfwRuleShape(
                comment: 'orbit:metrics-grafana-upstream',
                action: 'allow',
                direction: 'in',
                source: '10.44.0.1',
                destination: '10.44.0.3',
                port: '3000',
                protocol: 'tcp',
                inInterface: 'orbit',
                outInterface: null,
                family: 'v4',
            ),
        ])
        ->and(array_map(static fn (UfwManagedRule $rule): array => $rule->arguments, $rules))
        ->toBe([
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'proto',
                'tcp',
                'from',
                '10.44.0.3',
                'to',
                '10.44.0.4',
                'port',
                '9100',
                'comment',
                'orbit:metrics-node-exporter',
            ],
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'proto',
                'tcp',
                'from',
                '10.44.0.1',
                'to',
                '10.44.0.3',
                'port',
                '3000',
                'comment',
                'orbit:metrics-grafana-upstream',
            ],
        ]);
});
