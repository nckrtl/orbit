<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\MetricsFootprint;

describe(MetricsConfigurationRenderer::class, function (): void {
    it('generates exactly the files MetricsFootprint::ConfigurationPaths names', function (): void {
        $renderer = new MetricsConfigurationRenderer;

        $bundle = $renderer->render(metricsRendererTargets(), 'admin-password');

        $generatedPaths = array_map(
            static fn (App\Infrastructure\Metrics\MetricsGeneratedFile $file): string => $file->path,
            $bundle->files,
        );

        sort($generatedPaths);
        $expectedPaths = MetricsFootprint::ConfigurationPaths;
        sort($expectedPaths);

        expect($generatedPaths)->toBe($expectedPaths);
    });

    it('hashes each service against the files that service reads', function (): void {
        $renderer = new MetricsConfigurationRenderer;

        $first = $renderer->render(metricsRendererTargets(), 'admin-password');
        $second = $renderer->render(metricsRendererTargets(), 'admin-password');

        expect($first->prometheusHash)
            ->toMatch('/^[a-f0-9]{64}$/')
            ->and($first->grafanaHash)
            ->toMatch('/^[a-f0-9]{64}$/')
            ->and($first->prometheusHash)
            ->not
            ->toBe($first->grafanaHash)
            ->and($second->prometheusHash)
            ->toBe($first->prometheusHash)
            ->and($second->grafanaHash)
            ->toBe($first->grafanaHash);
    });

    it('moves only the Prometheus hash when the scrape targets change', function (): void {
        $renderer = new MetricsConfigurationRenderer;

        $before = $renderer->render(metricsRendererTargets(), 'admin-password');
        $after = $renderer->render(
            [...metricsRendererTargets(), ['name' => 'app-prod', 'address' => '10.44.0.4']],
            'admin-password',
        );

        expect($after->prometheusHash)
            ->not
            ->toBe($before->prometheusHash)
            ->and($after->grafanaHash)
            ->toBe($before->grafanaHash);
    });

    it('keeps the provisioned dashboard out of both hashes', function (): void {
        $renderer = new MetricsConfigurationRenderer;

        $before = $renderer->render(metricsRendererTargets(), 'admin-password');
        $after = $renderer->render(
            [...metricsRendererTargets(), ['name' => 'app-prod', 'address' => '10.44.0.4']],
            'admin-password',
        );

        expect(metricsRendererFileHash($before, '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json'))
            ->not
            ->toBe(metricsRendererFileHash($after, '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json'))
            ->and($after->grafanaHash)
            ->toBe($before->grafanaHash);
    });

    it('excludes the Grafana credential from both hashes', function (): void {
        $renderer = new MetricsConfigurationRenderer;

        $first = $renderer->render(metricsRendererTargets(), 'first-password');
        $second = $renderer->render(metricsRendererTargets(), 'second-password');

        expect($second->prometheusHash)
            ->toBe($first->prometheusHash)
            ->and($second->grafanaHash)
            ->toBe($first->grafanaHash)
            ->and(metricsRendererFileHash($second, '/etc/orbit/metrics/grafana/admin-password'))
            ->toBe(hash('sha256', 'second-password'));
    });

    it('refuses to render without a Grafana admin password', function (): void {
        expect(fn () => new MetricsConfigurationRenderer()->render(metricsRendererTargets(), ''))
            ->toThrow(InvalidArgumentException::class, 'admin password is unavailable');
    });
});

/** @return list<array{name: string, address: string}> */
function metricsRendererTargets(): array
{
    return [
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'app-dev', 'address' => '10.44.0.3'],
    ];
}

function metricsRendererFileHash(
    App\Infrastructure\Metrics\MetricsConfigurationBundle $bundle,
    string $path,
): string {
    foreach ($bundle->files as $file) {
        if ($file->path === $path) {
            return $file->contents->sha256();
        }
    }

    throw new RuntimeException("The bundle has no file at [{$path}].");
}
