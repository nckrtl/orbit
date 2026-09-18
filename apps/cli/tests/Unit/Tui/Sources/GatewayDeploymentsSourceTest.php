<?php

declare(strict_types=1);

use App\Support\Tui\Sources\GatewayDeploymentsSource;
use Orbit\Sdk\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);

describe(GatewayDeploymentsSource::class, function (): void {
    it('maps the recorded deployment list response into Deployments pane rows', function (): void {
        $source = new GatewayDeploymentsSource(gateway_fixture_send('instances/instance-deployment-list/default'));

        $rows = $source->forInstance(1);

        expect($rows)->toBe([
            [
                'id' => 1,
                'release' => '20260101000000',
                'branch' => 'main',
                'commit' => 'aaaaaaa',
                'started' => '2026-01-01T00:00:00+00:00',
                'finished' => '2026-01-01T00:00:42+00:00',
                'duration' => '42s',
                'status' => 'succeeded',
                'failed_step' => null,
                'error_code' => null,
                'selected_release' => '20260101000000',
                'by' => 'gateway',
            ],
        ]);
    });

    it('returns null when the Gateway request fails', function (): void {
        $source = new GatewayDeploymentsSource(fn (): never => throw new GatewayApiException('nope', 'gateway.request_failed'));

        expect($source->forInstance(1))->toBeNull();
    });
});
