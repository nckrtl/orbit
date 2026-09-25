<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsRequesterResolver;
use App\Infrastructure\AppDev\DnsAddress;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/**
 * Resolves a requester from the active Nodes in the Gateway database, for handler tests without a catalog.
 */
final readonly class RegisteredNodeDnsRequesterResolver implements PrivateDnsRequesterResolver
{
    public function resolve(string $sourceAddress): DnsRequester
    {
        $normalized = DnsAddress::normalize($sourceAddress);
        if ($normalized === null) {
            return DnsRequester::unidentified($sourceAddress);
        }

        $node = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->where('wireguard_ip', $normalized)
            ->first();

        return $node instanceof Node
            ? DnsRequester::registered($node->id, $normalized)
            : DnsRequester::unidentified($normalized);
    }
}
