<?php

declare(strict_types=1);

use App\Support\Tui\Sources\GatewayNodeMetricsSource;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;
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

    it('puts the root mount first even when a pseudo-filesystem like efivars is listed before it', function (): void {
        $source = new GatewayNodeMetricsSource(fn (): NodeMetricsResponse => new NodeMetricsResponse(
            nodeId: 1,
            nodeName: 'beast',
            cores: [0.1],
            memory: ['used' => 0, 'total' => 0],
            swap: ['used' => 0, 'total' => 0],
            load: ['one' => 0.0, 'five' => 0.0, 'fifteen' => 0.0],
            uptimeSeconds: 0,
            pressure: ['cpu' => ['some_avg10' => 0.0], 'memory' => ['some_avg10' => 0.0], 'io' => ['some_avg10' => 0.0]],
            disks: [
                ['mount' => '/sys/firmware/efi/efivars', 'used' => 0, 'total' => 0],
                ['mount' => '/', 'used' => 6442450944, 'total' => 85899345920],
                ['mount' => '/data', 'used' => 1073741824, 'total' => 107374182400],
            ],
            requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ));

        $metrics = $source->forNode(1);

        expect($metrics['disks'][0][0])->toBe('/');
    });

    it('falls back to the largest non-pseudo filesystem when the Node reports no root mount', function (): void {
        $source = new GatewayNodeMetricsSource(fn (): NodeMetricsResponse => new NodeMetricsResponse(
            nodeId: 1,
            nodeName: 'beast',
            cores: [0.1],
            memory: ['used' => 0, 'total' => 0],
            swap: ['used' => 0, 'total' => 0],
            load: ['one' => 0.0, 'five' => 0.0, 'fifteen' => 0.0],
            uptimeSeconds: 0,
            pressure: ['cpu' => ['some_avg10' => 0.0], 'memory' => ['some_avg10' => 0.0], 'io' => ['some_avg10' => 0.0]],
            disks: [
                ['mount' => '/sys/firmware/efi/efivars', 'used' => 0, 'total' => 0],
                ['mount' => '/boot', 'used' => 107374182, 'total' => 1073741824],
                ['mount' => '/data', 'used' => 1073741824, 'total' => 107374182400],
            ],
            requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ));

        $metrics = $source->forNode(1);

        expect($metrics['disks'][0][0])->toBe('/data');
    });
});
