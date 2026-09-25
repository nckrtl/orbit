<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequesterIdentity;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\RegisteredNodeDnsRequesterResolver;
use App\Infrastructure\AppDev\WireGuardDnsRequesterResolver;
use App\Models\Node;

it('normalizes a registered Node from its WireGuard source address', function (): void {
    $node = Node::query()->create([
        'name' => 'peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'wireguard_ip' => '10.44.0.8',
        'user' => 'orbit',
    ]);

    $requester = new RegisteredNodeDnsRequesterResolver()->resolve('10.44.0.8');

    expect($requester->identity)
        ->toBe(DnsRequesterIdentity::Registered)
        ->and($requester->nodeId)
        ->toBe($node->id)
        ->and($requester->sourceAddress)
        ->toBe('10.44.0.8');
});

it('treats an unknown or inactive WireGuard source as unidentified', function (string $source, ?string $status): void {
    if ($status !== null) {
        Node::query()->create([
            'name' => 'inactive-peer',
            'status' => LifecycleStatus::from($status),
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.9',
            'wireguard_ip' => '10.44.0.9',
            'user' => 'orbit',
        ]);
    }

    $requester = new RegisteredNodeDnsRequesterResolver()->resolve($source);

    expect($requester->identity)
        ->toBe(DnsRequesterIdentity::Unidentified)
        ->and($requester->nodeId)
        ->toBeNull();
})->with([
    'unknown address' => ['10.44.0.99', null],
    'inactive node' => ['10.44.0.9', LifecycleStatus::Failed->value],
]);

it('resolves published requester maps after a service restart without using DNS content', function (): void {
    $resolver = WireGuardDnsRequesterResolver::fromPublished([
        '10.44.0.8' => 17,
    ]);

    expect($resolver->resolve('10.44.0.8')->nodeId)
        ->toBe(17)
        ->and($resolver->resolve('10.44.0.99')->identity)
        ->toBe(DnsRequesterIdentity::Unidentified);
});
