<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsRequesterResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class WireGuardDnsRequesterResolver implements PrivateDnsRequesterResolver
{
    /**
     * @param  array<string, int>|null  $requesters
     */
    public function __construct(
        private ?array $requesters = null,
    ) {}

    /**
     * @param  array<string, int>  $requesters
     */
    public static function fromPublished(array $requesters): self
    {
        $normalized = [];

        foreach ($requesters as $address => $nodeId) {
            $canonical = DnsAddress::normalize((string) $address);

            if ($canonical !== null) {
                $normalized[$canonical] = $nodeId;
            }
        }

        return new self($normalized);
    }

    public function resolve(string $sourceAddress): DnsRequester
    {
        $normalized = DnsAddress::normalize($sourceAddress);
        if ($normalized === null) {
            return DnsRequester::unidentified($sourceAddress);
        }

        $nodeId = $this->requesters === null
            ? $this->registeredNodeId($normalized)
            : $this->requesters[$normalized] ?? null;

        if ($nodeId === null) {
            return DnsRequester::unidentified($normalized);
        }

        return DnsRequester::registered($nodeId, $normalized);
    }

    private function registeredNodeId(string $sourceAddress): ?int
    {
        $node = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->where('wireguard_ip', $sourceAddress)
            ->first();

        return $node instanceof Node ? $node->id : null;
    }
}
