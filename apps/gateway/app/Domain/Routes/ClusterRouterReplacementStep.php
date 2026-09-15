<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum ClusterRouterReplacementStep: string
{
    case RouterCertificate = 'router-certificate';
    case FirewallPolicy = 'firewall-policy';
    case WorkloadVerified = 'workload-verified';
    case RouterCaddy = 'router-caddy';
    case DnsPublished = 'dns-publication';
    case DatabaseCutover = 'database';
    case Cleanup = 'cleanup';

    public function rank(): int
    {
        return match ($this) {
            self::RouterCertificate => 1,
            self::FirewallPolicy => 2,
            self::WorkloadVerified => 3,
            self::RouterCaddy => 4,
            self::DnsPublished => 5,
            self::DatabaseCutover => 6,
            self::Cleanup => 7,
        };
    }

    public static function fromFailedStep(?string $step): ?self
    {
        if (! is_string($step) || $step === '') {
            return null;
        }

        if (str_starts_with($step, 'rollback:')) {
            return self::tryFrom(substr($step, strlen('rollback:')));
        }

        return self::tryFrom($step);
    }

    public static function isReplacementProgress(?string $step): bool
    {
        return self::fromFailedStep($step) instanceof self;
    }
}
