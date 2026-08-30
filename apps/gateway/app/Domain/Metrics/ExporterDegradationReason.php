<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Why one fleet node was left out of the last Metrics exporter convergence.
 *
 * Fleet mutation is synchronous, so a node that cannot be inspected would
 * otherwise hold every node removal and role change hostage. A degraded node
 * is skipped instead, and its recorded reason is what `metrics:status` shows
 * in place of an exporter state nobody could read.
 */
enum ExporterDegradationReason: string
{
    case Unreachable = 'unreachable';
    case FirewallInactive = 'firewall_inactive';

    /**
     * Maps a stable exporter error code onto the degradation it justifies.
     *
     * Only the codes that describe the node's own availability degrade. An
     * ownership or address failure means Orbit cannot prove what it owns, and
     * that keeps failing the whole mutation.
     */
    public static function fromErrorCode(string $errorCode): ?self
    {
        return match ($errorCode) {
            'metrics.exporter_configuration_inspection_failed',
            'metrics.exporter_service_inspection_failed',
            'metrics.exporter_firewall_inspection_failed',
                => self::Unreachable,
            'metrics.exporter_firewall_inactive' => self::FirewallInactive,
            default => null,
        };
    }
}
