<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The private-DNS listener release, its units, the dnsmasq records, and the catalog on the `vpn` Node,
 * when that Node is not the Gateway's. The publication compares every file with the live copy and
 * restarts the listener or dnsmasq only for a change.
 */
final readonly class PrivateDnsFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(private PrivateDnsManager $dns) {}

    public function name(): string
    {
        return 'private-dns';
    }

    public function applies(Node $node): bool
    {
        $holds = static fn (Node $candidate, RoleName $role): bool => $candidate->roles->contains(
            static fn (NodeRole $assignment): bool => $assignment->role === $role && $assignment->status === LifecycleStatus::Active,
        );

        return $holds($node, RoleName::Vpn) && ! $holds($node, RoleName::Gateway);
    }

    /**
     * The listener release and the publication code, not the records: a Route or Node change publishes its
     * own records and never makes the `vpn` Node drift.
     */
    public function digest(Node $node): string
    {
        return hash('sha256', PrivateDnsListenerRelease::fromGateway()->id()."\n".SourceDigest::of([
            'app/Infrastructure/AppDev/DnsmasqPrivateDnsManager.php',
            'app/Infrastructure/AppDev/DevelopmentDnsConfigRenderer.php',
            'app/Infrastructure/AppDev/PrivateDnsListenerUnitRenderer.php',
        ]));
    }

    public function apply(Node $node): ?bool
    {
        $this->dns->converge();

        return null;
    }
}
