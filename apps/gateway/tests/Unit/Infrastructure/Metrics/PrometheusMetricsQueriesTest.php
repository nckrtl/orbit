<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\PrometheusMetricsQueries;

describe(PrometheusMetricsQueries::class, function (): void {
    it('keeps the three pressure rates apart with a label of their own', function (): void {
        $query = PrometheusMetricsQueries::pressure();

        // PromQL drops `__name__` in a binary operation, so a union that relied on the metric
        // name collapsed to whichever rate came first, and Prometheus rejected the regex form
        // outright with "vector cannot contain metrics with the same labelset".
        expect($query)
            ->not->toContain('__name__')
            ->and(substr_count($query, 'label_replace('))->toBe(3)
            ->and(substr_count($query, ' or '))->toBe(2);

        foreach (['cpu', 'memory', 'io'] as $kind) {
            expect($query)
                ->toContain('node_pressure_'.$kind.'_waiting_seconds_total')
                ->toContain('"'.PrometheusMetricsQueries::PRESSURE_KIND_LABEL.'","'.$kind.'"');
        }
    });

    it('scopes every query to one instance when asked', function (): void {
        $instance = '10.44.0.3:9100';

        foreach ([
            PrometheusMetricsQueries::scalars($instance),
            PrometheusMetricsQueries::cores($instance),
            PrometheusMetricsQueries::pressure($instance),
            PrometheusMetricsQueries::disks($instance),
        ] as $query) {
            expect($query)->toContain('instance="'.$instance.'"');
        }
    });

    it('leaves every query fleet wide when no instance is given', function (): void {
        foreach ([
            PrometheusMetricsQueries::scalars(),
            PrometheusMetricsQueries::cores(),
            PrometheusMetricsQueries::pressure(),
            PrometheusMetricsQueries::disks(),
        ] as $query) {
            expect($query)->not->toContain('instance=');
        }
    });
});
