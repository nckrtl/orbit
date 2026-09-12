<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteHostnameChangeStep: string
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
    case DnsPublished = 'dns-published';
    case DatabaseCutover = 'database-cutover';
    case RollbackPending = 'rollback-pending';
    case RollbackDns = 'rollback-dns';
    case RollbackCaddy = 'rollback-caddy';
    case RollbackCertificates = 'rollback-certificates';
    case RollbackLaravelUrl = 'rollback-laravel-url';
    case RollbackEnvironment = 'rollback-environment';
    case RolledBack = 'rolled-back';
}
