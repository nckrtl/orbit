<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteReplacementStep: string
{
    case Reserved = 'reserved';
    case WorkloadCertificate = 'workload-certificate';
    case WorkloadCaddy = 'workload-caddy';
    case RouterCertificate = 'router-certificate';
    case FirewallPolicy = 'firewall-policy';
    case WorkloadVerified = 'workload-verified';
    case RouterCaddy = 'router-caddy';
    case LaravelUrl = 'laravel-url';
    case EnvironmentSynchronized = 'environment-synchronized';
    case IngressCertificate = 'ingress-certificate';
    /** No step runs it any more. Stored checkpoints of Routes published before the Node Caddy build keep it. */
    case IngressCaddy = 'ingress-caddy';
    case IngressFirewall = 'ingress-firewall';
    case PublicEdgeVerified = 'public-edge-verified';
    case DnsPublished = 'dns-published';
    case DatabaseCutover = 'database-cutover';
    case PublicActivated = 'public-activated';
    case Cleanup = 'cleanup';

    /** The order in which a Route change completes its steps. */
    public function rank(): int
    {
        return match ($this) {
            self::Reserved => 0,
            self::WorkloadCertificate => 1,
            self::WorkloadCaddy => 2,
            self::RouterCertificate => 3,
            self::FirewallPolicy => 4,
            self::WorkloadVerified => 5,
            self::RouterCaddy => 6,
            self::IngressCertificate => 7,
            self::IngressCaddy => 8,
            self::PublicEdgeVerified => 9,
            self::LaravelUrl, self::EnvironmentSynchronized => 10,
            self::DnsPublished => 11,
            self::DatabaseCutover => 12,
            self::PublicActivated => 13,
            self::IngressFirewall => 14,
            self::Cleanup => 15,
        };
    }

    public function hasReached(self $step): bool
    {
        return $this->rank() >= $step->rank();
    }
}
