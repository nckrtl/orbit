<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class ServiceMetricsDashboardRenderer
{
    public function render(string $kind): string
    {
        $selector = '{service="'.$kind.'",node=~"$node"}';
        $caddySelector = 'service="caddy",node=~"$node"';
        $rate = static fn (string $metric, string $extra = ''): string => 'rate('.$metric.'{'.$caddySelector.',handler="subroute"'.$extra.'}[5m])';
        $panels = $kind === 'caddy' ? [
            ['Scrape health', 'up'.$selector, 'short'],
            ['Observed requests / second', 'sum by (node, host) ('.$rate('caddy_http_request_duration_seconds_count').')', 'reqps'],
            ['5xx responses / second', 'sum by (node, host) ('.$rate('caddy_http_request_duration_seconds_count', ',code=~"5.."').')', 'reqps'],
            ['Request duration p95', 'histogram_quantile(0.95, sum by (le, node, host) ('.$rate('caddy_http_request_duration_seconds_bucket').'))', 's'],
            ['Time to first byte p95', 'histogram_quantile(0.95, sum by (le, node, host) ('.$rate('caddy_http_response_duration_seconds_bucket').'))', 's'],
            ['Observed requests in flight', 'sum by (node, host) (caddy_http_requests_in_flight{'.$caddySelector.',handler="subroute"})', 'short'],
        ] : [
            ['Scrape health', 'up'.$selector, 'short'],
            ['Pool health', 'phpfpm_up'.$selector, 'short'],
            ['Active workers', 'phpfpm_active_processes'.$selector, 'short'],
            ['Worker capacity', 'phpfpm_pm_max_children_config'.$selector, 'short'],
            ['Waiting requests', 'phpfpm_listen_queue'.$selector, 'short'],
            ['Worker limit reached / second', 'rate(phpfpm_max_children_reached'.$selector.'[5m])', 'ops'],
            ['Slow requests / second (requires slowlog)', 'rate(phpfpm_slow_requests'.$selector.'[5m]) and on (node, pool) (phpfpm_request_slowlog_timeout_config'.$selector.' > 0)', 'reqps'],
            ['OPcache used memory', 'phpfpm_opcache_used_memory_bytes'.$selector.' and on (node, pool) (phpfpm_opcache_enabled'.$selector.' == 1)', 'bytes'],
            ['OPcache hit rate', 'phpfpm_opcache_hit_rate'.$selector.' and on (node, pool) (phpfpm_opcache_enabled'.$selector.' == 1)', 'percent'],
            ['OPcache manual resets', 'increase(phpfpm_opcache_manual_restarts_total'.$selector.'[5m]) and on (node, pool) (phpfpm_opcache_enabled'.$selector.' == 1)', 'short'],
        ];
        $rendered = [];
        foreach ($panels as $index => [$title, $expr, $unit]) {
            $rendered[] = [
                'id' => $index + 1, 'title' => $title, 'type' => 'timeseries',
                'description' => $kind === 'fpm' && $index === 1 ? 'Cbox can omit unavailable pools. Compare these series with the expected App instances; endpoint health alone does not prove every pool is healthy.' : '',
                'datasource' => ['type' => 'prometheus', 'uid' => 'orbit-prometheus'],
                'gridPos' => ['x' => ($index % 2) * 12, 'y' => intdiv($index, 2) * 8, 'w' => 12, 'h' => 8],
                'targets' => [['refId' => 'A', 'expr' => $expr, 'legendFormat' => $index === 0 ? '{{node}}' : ($kind === 'fpm' ? '{{node}} / instance {{app_instance_id}} / {{pool}}' : '{{node}} {{host}}')]],
                'fieldConfig' => ['defaults' => ['unit' => $unit, 'custom' => ['spanNulls' => false]], 'overrides' => []],
                'options' => ['legend' => ['displayMode' => 'list', 'placement' => 'bottom']],
            ];
        }

        return json_encode([
            'uid' => 'orbit-'.$kind, 'title' => $kind === 'caddy' ? 'Orbit Caddy Traffic' : 'Orbit PHP Capacity',
            'description' => $kind === 'caddy'
                ? 'Outer site-handler observations. Caddy 2.9+ supplies host labels; older versions cover the shared HTTPS listener without host labels. Private traffic to the same site may be included. Next-hop health is not application health.'
                : 'Dedicated production masters. Missing data is not zero. OPcache panels require a successful enabled-cache observation.',
            'schemaVersion' => 39, 'version' => 1, 'refresh' => '30s', 'tags' => ['orbit', $kind],
            'time' => ['from' => 'now-1h', 'to' => 'now'], 'timezone' => 'browser',
            'templating' => ['list' => [[
                'name' => 'node', 'type' => 'query', 'label' => 'Node', 'multi' => true,
                'includeAll' => true, 'allValue' => '.*', 'refresh' => 1,
                'datasource' => ['type' => 'prometheus', 'uid' => 'orbit-prometheus'],
                'query' => 'label_values(up{service="'.$kind.'"}, node)',
                'current' => ['text' => 'All', 'value' => '$__all'],
            ]]],
            'panels' => $rendered,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
