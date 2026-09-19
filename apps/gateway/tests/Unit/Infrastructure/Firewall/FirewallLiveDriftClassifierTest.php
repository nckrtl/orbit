<?php

declare(strict_types=1);

use App\Infrastructure\Firewall\FirewallLiveDriftClassifier;
use App\Infrastructure\Firewall\UfwRuleShape;

it('marks a live rule with no desired or operator comment as unmanaged drift', function (): void {
    $live = new UfwRuleShape('orbit:public-ssh-recovery', 'allow', 'in', 'any', 'any', '22', 'tcp', null, null, 'v4');
    $expected = new UfwRuleShape('orbit:wireguard-members', 'allow', 'in', 'any', '10.44.0.3', 'any', 'any', 'orbit', null, 'v4');

    $classified = new FirewallLiveDriftClassifier()->classify([$live], [$expected]);

    expect($classified['live'][0]['match'])
        ->toBe('unmanaged')
        ->and($classified['missing'])
        ->toEqual([$expected]);
});

it('marks a live rule whose comment matches but whose shape does not as drift', function (): void {
    $expected = new UfwRuleShape('orbit:wireguard-members', 'allow', 'in', 'any', '10.44.0.3', 'any', 'any', 'orbit', null, 'v4');
    $live = new UfwRuleShape('orbit:wireguard-members', 'allow', 'in', 'any', 'any', 'any', 'any', null, null, 'v4');

    $classified = new FirewallLiveDriftClassifier()->classify([$live], [$expected]);

    expect($classified['live'][0]['match'])
        ->toBe('drift')
        ->and($classified['missing'])
        ->toBe([]);
});

it('marks an exact live match and does not also list it as missing', function (): void {
    $expected = new UfwRuleShape('orbit:wireguard-members', 'allow', 'in', 'any', '10.44.0.3', 'any', 'any', 'orbit', null, 'v4');

    $classified = new FirewallLiveDriftClassifier()->classify([$expected], [$expected]);

    expect($classified['live'][0]['match'])
        ->toBe('exact')
        ->and($classified['missing'])
        ->toBe([]);
});
