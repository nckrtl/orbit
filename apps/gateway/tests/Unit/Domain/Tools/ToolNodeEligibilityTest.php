<?php

declare(strict_types=1);

use App\Domain\Tools\ToolNodeEligibility;
use App\Models\Node;

it('allows tools on a verified Linux or macOS node and nowhere else', function (array $attributes, bool $allowed): void {
    $node = new Node(array_merge([
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ], $attributes));

    expect(new ToolNodeEligibility()->allows($node))->toBe($allowed);
})->with([
    'managed Linux node' => [[], true],
    'verified mac' => [['platform' => 'macos'], true],
    'stored darwin name' => [['platform' => 'darwin'], false],
    'missing WireGuard identity' => [['wireguard_ip' => null], false],
    'blank WireGuard identity' => [['wireguard_ip' => ''], false],
    'missing pinned SSH identity' => [['ssh_host_fingerprint' => null], false],
    'blank pinned SSH identity' => [['ssh_host_fingerprint' => ''], false],
    'mac without a pinned SSH identity' => [['platform' => 'macos', 'ssh_host_fingerprint' => null], false],
]);
