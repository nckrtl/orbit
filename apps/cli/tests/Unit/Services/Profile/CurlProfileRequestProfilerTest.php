<?php

declare(strict_types=1);

use App\Services\Profile\CurlProfileRequestProfiler;
use App\Services\Profile\ProfileHumanRenderer;

describe('CurlProfileRequestProfiler', function (): void {
    it('derives TLS timing from microsecond app connect timing when available', function (): void {
        $timings = cliCurlProfileTimingsFromInfo([
            'namelookup_time_us' => 2100,
            'connect_time_us' => 6226,
            'appconnect_time_us' => 12837,
            'starttransfer_time_us' => 48550,
            'total_time_us' => 61234,
        ]);

        expect($timings)
            ->toMatchArray([
                'dns_ms' => 2.1,
                'connect_ms' => 6.23,
                'tls_ms' => 6.61,
                'ttfb_ms' => 48.55,
                'total_ms' => 61.23,
            ]);
    });

    it('prefers microsecond timing over second timing', function (): void {
        $timings = cliCurlProfileTimingsFromInfo([
            'connect_time' => 0.006,
            'appconnect_time' => 0.006,
            'connect_time_us' => 6000,
            'appconnect_time_us' => 13000,
        ]);

        expect($timings['tls_ms'])->toBe(7.0);
    });

    it('falls back to pretransfer timing when app connect timing is absent', function (): void {
        $timings = cliCurlProfileTimingsFromInfo([
            'connect_time_us' => 6000,
            'pretransfer_time_us' => 13000,
        ]);

        expect($timings['tls_ms'])->toBe(7.0);
    });

    it('preserves second-based TLS timing derivation', function (): void {
        $timings = cliCurlProfileTimingsFromInfo([
            'connect_time' => 0.006,
            'appconnect_time' => 0.013,
        ]);

        expect($timings['tls_ms'])->toBe(7.0);
    });

    it('clamps negative TLS timing to zero', function (): void {
        $timings = cliCurlProfileTimingsFromInfo([
            'connect_time_us' => 13000,
            'appconnect_time_us' => 6000,
        ]);

        expect($timings['tls_ms'])->toBe(0.0);
    });
});

describe('ProfileHumanRenderer', function (): void {
    it('renders request and timing lines for a baseline profile', function (): void {
        $lines = (new ProfileHumanRenderer)->lines([
            'request' => [
                'method' => 'GET',
                'url' => 'https://docs.test/admin',
                'status' => 200,
                'bytes' => 45120,
            ],
            'timings' => [
                'dns_ms' => 2.15,
                'connect_ms' => 5.2,
                'tls_ms' => 8.1,
                'ttfb_ms' => 110.3,
                'download_ms' => 5.12,
                'total_ms' => 115.42,
            ],
        ]);

        expect($lines[0])
            ->toBe('GET https://docs.test/admin 200 in 115.42ms')
            ->and($lines)
            ->toContain('DNS ..................................... 2.15ms')
            ->and($lines)
            ->toContain('Total ................................. 115.42ms')
            ->and($lines)
            ->toContain('Download response ....................... 5.12ms - 44.1KB');
    });
});

/**
 * @param  array<string, mixed>  $info
 * @return array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float}
 */
function cliCurlProfileTimingsFromInfo(array $info): array
{
    $method = new ReflectionMethod(CurlProfileRequestProfiler::class, 'timingsFromCurlInfo');
    /** @var array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float} $timings */
    $timings = $method->invoke(new CurlProfileRequestProfiler, $info);

    return $timings;
}
