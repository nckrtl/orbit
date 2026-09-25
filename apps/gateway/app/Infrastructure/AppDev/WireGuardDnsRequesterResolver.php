<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsRequesterResolver;

/**
 * Resolves requesters from the published catalog. The listener release carries this class, so it uses no framework
 * code (ADR 0149).
 */
final readonly class WireGuardDnsRequesterResolver implements PrivateDnsRequesterResolver
{
    /**
     * @param  array<string, int>  $requesters
     */
    public function __construct(
        private array $requesters = [],
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

        $nodeId = $this->requesters[$normalized] ?? null;

        if ($nodeId === null) {
            return DnsRequester::unidentified($normalized);
        }

        return DnsRequester::registered($nodeId, $normalized);
    }
}
