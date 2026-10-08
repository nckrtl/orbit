<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use Tests\Support\RecordingProcessRunner;

describe(GatewayCheckoutAccessConverger::class, function (): void {
    it('expects a checkout path that resolves to itself outside the release layout', function (): void {
        $processes = new RecordingProcessRunner;

        new GatewayCheckoutAccessConverger($processes, '/home/orbit/orbit/apps/gateway', static fn (): ?string => null)->converge();

        expect($processes->ran[0])->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/orbit/apps/gateway', '/home/orbit/orbit/apps/gateway'])
            ->and($processes->ran)->toContain(['sudo', 'chmod', '0750', '/home/orbit/orbit/apps/gateway/public']);
    });

    it('accepts the release link and leaves the read-only release alone', function (): void {
        $processes = new RecordingProcessRunner;

        new GatewayCheckoutAccessConverger($processes, '/home/orbit/orbit/apps/gateway', static fn (): ?string => '0123456789ab')->converge();

        expect($processes->ran)->toBe([
            ['sudo', 'bash', '-seu', '--', '/home/orbit/orbit/apps/gateway', '/home/orbit/releases/0123456789ab/apps/gateway'],
            ['sudo', 'chown', 'orbit:caddy', '/home/orbit', '/home/orbit/releases'],
            ['sudo', 'chmod', '0710', '/home/orbit', '/home/orbit/releases'],
            ['sudo', 'chmod', '0600', '/home/orbit/shared/gateway.env'],
        ]);
    });
});
