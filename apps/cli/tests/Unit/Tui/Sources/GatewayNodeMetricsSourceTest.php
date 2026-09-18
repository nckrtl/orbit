<?php

declare(strict_types=1);

use App\Support\Tui\Sources\GatewayNodeMetricsSource;
use Orbit\Sdk\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);

describe(GatewayNodeMetricsSource::class, function (): void {
    it('maps the recorded node metrics response into the compact metrics shape, bytes converted to GiB', function (): void {
        $source = new GatewayNodeMetricsSource(gateway_fixture_send('nodes/node-metrics/default'));

        $metrics = $source->forNode(1);

        expect($metrics)->toBe([
            'cores' => [0.12, 0.34, 0.08, 0.21],
            'mem' => [3435973836 / 1024 ** 3, (float) (8589934592 / 1024 ** 3)],
            'swap' => [0.0, (float) (2147483648 / 1024 ** 3)],
            'uptime' => '12d 4h 43m',
            'disks' => [['/', (float) (6442450944 / 1024 ** 3), (float) (85899345920 / 1024 ** 3)]],
        ]);
    });

    it('returns null when the Gateway request fails', function (): void {
        $source = new GatewayNodeMetricsSource(fn (): never => throw new GatewayApiException('nope', 'gateway.request_failed'));

        expect($source->forNode(1))->toBeNull();
    });
});
