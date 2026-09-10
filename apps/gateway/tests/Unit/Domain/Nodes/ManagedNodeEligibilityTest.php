<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Models\Node;

it('requires the supported platform and both managed identities', function (array $attributes, bool $eligible): void {
    $node = new Node(array_merge([
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ], $attributes));

    expect(new ManagedNodeEligibility()->allows($node))->toBe($eligible);
})->with([
    'managed Linux node' => [[], true],
    'unsupported platform' => [['platform' => 'darwin'], false],
    'missing WireGuard identity' => [['wireguard_ip' => null], false],
    'blank WireGuard identity' => [['wireguard_ip' => ''], false],
    'missing pinned SSH identity' => [['ssh_host_fingerprint' => null], false],
    'blank pinned SSH identity' => [['ssh_host_fingerprint' => ''], false],
]);
