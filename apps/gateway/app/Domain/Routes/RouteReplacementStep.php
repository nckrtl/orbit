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
    case IngressCaddy = 'ingress-caddy';
    case IngressFirewall = 'ingress-firewall';
    case PublicEdgeVerified = 'public-edge-verified';
    case DnsPublished = 'dns-published';
    case DatabaseCutover = 'database-cutover';
    case PublicActivated = 'public-activated';
    case Cleanup = 'cleanup';
}
