<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * Every trace Metrics leaves on a node, and the proof Orbit owns each one.
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
        '/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json',
        '/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json',
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

    public const string PublicationFirewallDenyComment = 'orbit:metrics-grafana-isolation';

    public const string WireGuardInterface = 'orbit';

    /**
     * Pinned cAdvisor release.
     *
     * cAdvisor reads cgroups directly, so it is the only way to get per-Process CPU and memory:
     * `node_exporter` exposes systemd unit *state* only. It ships no apt package, so unlike
     * `prometheus-node-exporter` it is a downloaded static binary, its checksum verified on the
     * Node before install (see `MetricsCadvisorSshExecutor`). linux-amd64 only: every exporter
     * Node today (app-prod, beast, gateway, sabre, shark) is an x86_64 Ubuntu VPS, matching the
     * apt-packaged node exporter's own architecture assumption.
     *
     * A version bump must re-derive `CadvisorChecksumSha256` from that release's
     * `cadvisor-{version}-linux-amd64` asset (cAdvisor publishes no checksum file of its own) and
     * re-run the beast cardinality proof this pin was measured against.
     */
    public const string CadvisorVersion = 'v0.60.5';

    /** SHA256 of `cadvisor-v0.60.5-linux-amd64`, computed from the GitHub release asset. */
    public const string CadvisorChecksumSha256 = '6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87';

    public const string CadvisorDownloadUrl = 'https://github.com/google/cadvisor/releases/download/'
        .self::CadvisorVersion.'/cadvisor-'.self::CadvisorVersion.'-linux-amd64';

    public const string CadvisorBinaryPath = '/usr/local/bin/orbit-cadvisor';

    public const string CadvisorUnitPath = '/etc/systemd/system/orbit-cadvisor.service';

    public const string CadvisorService = 'orbit-cadvisor';

    /** The first line of the unit, and the only proof that Orbit owns it. */
    public const string CadvisorUnitMarker = '# Managed by Orbit: metrics-cadvisor';

    /** The port cAdvisor listens on, and the port its UFW rule opens. */
    public const string CadvisorPort = '9102';

    public const string CadvisorFirewallComment = 'orbit:metrics-cadvisor';

    /**
     * cAdvisor's `--disable_metrics` kinds, keeping only `cpu` and `memory`.
     *
     * Measured on beast: an unfiltered cAdvisor added 8,359 series against node_exporter's 5,150,
     * at 3.1-4.5% of one core and 54-65 MiB. Every one of those extra series comes from a metric
     * kind `PrometheusProcessRuntimeStatusIndex` (CPU) and `MetricsCadvisorRuntime` callers (memory)
     * never query: per-interface network and TCP/UDP connection state, per-device disk IO, the
     * scheduler, process/thread counts, huge pages, NUMA, cpuset, resource control, and OOM events.
     * Disabling them here is what keeps the added cardinality proportional to two counters per
     * cgroup instead of dozens.
     *
     * @var non-empty-list<string>
     */
    public const array CadvisorDisabledMetrics = [
        'sched',
        'percpu',
        'memory_numa',
        'cpuLoad',
        'diskIO',
        'disk',
        'network',
        'tcp',
        'advtcp',
        'udp',
        'app',
        'process',
        'hugetlb',
        'referenced_memory',
        'cpu_topology',
        'resctrl',
        'cpuset',
        'oom_event',
        'pressure',
    ];
}
