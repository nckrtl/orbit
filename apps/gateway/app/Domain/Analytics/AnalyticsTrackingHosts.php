<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteDomain;

/** The rules for the public hosts an App instance publishes for Plausible's script and event paths. */
final readonly class AnalyticsTrackingHosts
{
    public const int MAXIMUM = 10;

    public const string SCRIPT_PATH = '/js/script.js';

    public const string EVENT_PATH = '/api/event';

    public static function defaultFor(string $instanceDomain): string
    {
        return RouteDomain::validate("analytics.{$instanceDomain}");
    }

    /** Why a host cannot be a tracking host, or null when it can. */
    public static function reason(string $host): ?string
    {
        $host = RouteDomain::normalize($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return 'A tracking host is a DNS name, not an IP address.';
        }

        if (! RouteDomain::isValid($host) || ! str_contains($host, '.')) {
            return 'A tracking host is a DNS host name such as analytics.example.com, without a scheme or a path.';
        }

        if (in_array($host, ReservedPrivateHostname::NAMES, true) || str_ends_with($host, '.orbit')) {
            return 'A tracking host cannot use the reserved private orbit names.';
        }

        return null;
    }

    public static function scriptUrl(string $host): string
    {
        return "https://{$host}".self::SCRIPT_PATH;
    }

    public static function eventUrl(string $host): string
    {
        return "https://{$host}".self::EVENT_PATH;
    }

    public static function snippet(string $siteDomain, string $host): string
    {
        return '<script defer data-domain="'.$siteDomain.'" src="'.self::scriptUrl($host).'"></script>';
    }
}
