<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class GrafanaConfigRenderer
{
    public function datasource(string $url = 'http://127.0.0.1:9090'): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid datasource URL.');
        }

        return "apiVersion: 1\ndatasources:\n  - name: Prometheus\n    type: prometheus\n    uid: orbit-prometheus\n    url: {$url}\n    access: proxy\n    isDefault: true\n";
    }

    public function dashboardProvider(): string
    {
        return "apiVersion: 1\nproviders:\n  - name: Orbit\n    type: file\n    disableDeletion: true\n    allowUiUpdates: false\n    options:\n      path: /var/lib/grafana/dashboards\n";
    }

    public function rootUrl(): string
    {
        return "[server]\ndomain = metrics.orbit\nroot_url = https://metrics.orbit\n";
    }
}
