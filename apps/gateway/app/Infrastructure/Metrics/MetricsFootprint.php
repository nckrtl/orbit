<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * Every trace Metrics leaves on a node, and the proof Orbit owns each one.
 *
 * The remote executors and the node-local escape script remove the same
 * resources through different transports. Naming a path, label or firewall
 * comment twice would let the two drift apart, and the escape would then
 * either miss a resource or delete one it cannot prove is Orbit's. Both read
 * these constants instead.
 */
final readonly class MetricsFootprint
{
    /** The label every Orbit Metrics container and volume carries. */
    public const string ManagedLabel = 'com.orbit.managed';

    public const string ManagedValue = 'metrics';

    public const string ConfigurationDirectory = '/etc/orbit/metrics';

    public const string OwnershipMarker = '/etc/orbit/metrics/.orbit-owner';

    public const string OwnershipMarkerContents = "metrics\n";

    /** The suffix every generated file is staged under before it is moved into place. */
    public const string CandidateSuffix = '.orbit-candidate';

    /** @var non-empty-list<string> Parents first, so removal walks them in reverse. */
    public const array ConfigurationDirectories = [
        '/etc/orbit/metrics',
        '/etc/orbit/metrics/grafana',
        '/etc/orbit/metrics/grafana/provisioning',
        '/etc/orbit/metrics/grafana/provisioning/datasources',
        '/etc/orbit/metrics/grafana/provisioning/dashboards',
        '/etc/orbit/metrics/grafana/dashboards',
    ];

    /** @var non-empty-list<string> */
    public const array ConfigurationPaths = [
        '/etc/orbit/metrics/prometheus.yml',
        '/etc/orbit/metrics/grafana/grafana.ini',
        '/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml',
        '/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml',
        '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json',
        '/etc/orbit/metrics/grafana/admin-password',
    ];

    public const string ExporterPackage = 'prometheus-node-exporter';

    public const string ExporterService = 'prometheus-node-exporter';

    public const string ExporterDropInDirectory = '/etc/systemd/system/prometheus-node-exporter.service.d';

    public const string ExporterDropIn = '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf';

    /** The first line of the drop-in, and the only proof that Orbit wrote it. */
    public const string ExporterDropInMarker = '# Managed by Orbit: metrics';

    /** The port the exporter listens on, and the port its UFW rule opens. */
    public const string ExporterPort = '9100';

    /** The port Grafana listens on, and the port the Gateway upstream rule opens. */
    public const string PublicationPort = '3000';

    public const string ExporterFirewallComment = 'orbit:metrics-node-exporter';

    public const string PublicationFirewallComment = 'orbit:metrics-grafana-upstream';

    public const string WireGuardInterface = 'orbit';

    /** The node-local escape, published beside the exporter drop-in it removes. */
    public const string UninstallScript = '/usr/local/sbin/orbit-metrics-uninstall';
}
