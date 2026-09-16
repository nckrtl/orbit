<?php

declare(strict_types=1);

namespace App\Services\Profile;

final class ProfileHumanRenderer
{
    private const int LINE_WIDTH = 48;

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function lines(array $data): array
    {
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        $timings = is_array($data['timings'] ?? null) ? $data['timings'] : [];
        $method = is_string($request['method'] ?? null) && $request['method'] !== '' ? $request['method'] : 'GET';
        $url = is_string($request['url'] ?? null) ? $request['url'] : '';
        $status = $request['status'] ?? '-';
        $totalMs = $this->timingMs($timings, 'total_ms');
        $dnsMs = $this->timingMs($timings, 'dns_ms');
        $connectTotalMs = $this->timingMs($timings, 'connect_ms');
        $tlsMs = $this->timingMs($timings, 'tls_ms');
        $waitingMs = max(0.0, $this->timingMs($timings, 'ttfb_ms') - $connectTotalMs - $tlsMs);
        $bytes = is_numeric($request['bytes'] ?? null) ? (int) $request['bytes'] : 0;
        $lines = [
            "{$method} {$url} {$status} in ".$this->formatMs($totalMs).'ms',
            '',
            $this->dottedLine('DNS', $this->formatMs($dnsMs).'ms'),
            $this->dottedLine('Connect', $this->formatMs(max(0.0, $connectTotalMs - $dnsMs)).'ms'),
            $this->dottedLine('TLS', $this->formatMs($tlsMs).'ms'),
            $this->dottedLine('Waiting for response', $this->formatMs($waitingMs).'ms'),
        ];

        if (is_array($data['toolbar'] ?? null)) {
            $lines = [
                ...$lines,
                ...$this->toolbarLines($waitingMs, $data['toolbar'], $data['response_headers'] ?? []),
            ];
        }

        $lines[] = $this->dottedLine(
            'Download response',
            $this->formatMs($this->timingMs($timings, 'download_ms')).'ms',
            suffix: ' - '.$this->formatBytes($bytes),
        );
        $lines[] = $this->dottedLine('Total', $this->formatMs($totalMs).'ms');

        if (is_array($data['toolbar'] ?? null)) {
            $queries = is_array($data['toolbar']['queries'] ?? null) ? $data['toolbar']['queries'] : [];
            $querySummary = $this->toolbarQuerySummary($queries);

            if ($querySummary !== null) {
                $lines[] = '';
                $lines[] = $querySummary;
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $toolbar
     * @return list<string>
     */
    private function toolbarLines(float $waitingMs, array $toolbar, mixed $responseHeaders): array
    {
        $headers = is_array($responseHeaders) ? $responseHeaders : [];
        $anchors = is_array($toolbar['timing_anchors'] ?? null) ? $toolbar['timing_anchors'] : [];
        $caddyStart = $this->numericValue($anchors, 'caddy_start_ms');
        $phpStart = $this->numericValue($anchors, 'php_start_ms');
        $laravelStart = $this->numericValue($anchors, 'laravel_start_ms');
        $profilerEnd = $this->numericValue($anchors, 'profiler_end_ms');
        $collectedAt = $this->numericValue($anchors, 'collected_at_ms');
        $accountedMs = 0.0;
        $lines = [];

        if ($caddyStart !== null && $phpStart !== null) {
            $duration = max(0.0, $phpStart - $caddyStart);
            $accountedMs += $duration;
            $lines[] = $this->dottedLine('orbit-caddy in', $this->formatMs($duration).'ms', indent: 2);
        }

        if ($phpStart !== null && $laravelStart !== null) {
            $duration = max(0.0, $laravelStart - $phpStart);
            $accountedMs += $duration;
            $lines[] = $this->dottedLine('FrankenPHP', $this->formatMs($duration).'ms', indent: 2);
        }

        $profiler = is_array($toolbar['profiler'] ?? null) ? $toolbar['profiler'] : [];
        $stages = is_array($profiler['stages'] ?? null) ? $profiler['stages'] : [];

        foreach ($stages as $stage) {
            if (! is_array($stage) || ! is_string($stage['label'] ?? null)) {
                continue;
            }

            $duration = $this->stageDurationMs($stage);

            if ($duration === null) {
                continue;
            }

            $accountedMs += $duration;
            $lines[] = $this->dottedLine($stage['label'], $this->formatMs($duration).'ms', indent: 2);
        }

        if ($profilerEnd !== null && $collectedAt !== null) {
            $duration = max(0.0, $collectedAt - $profilerEnd);
            $accountedMs += $duration;
            $lines[] = $this->dottedLine('Toolbar', $this->formatMs($duration).'ms', indent: 2);
        }

        $caddyEnd = $this->headerFloat($headers, 'x-caddy-end');

        if ($caddyEnd !== null && $collectedAt !== null) {
            $duration = max(0.0, $caddyEnd - $collectedAt);
            $accountedMs += $duration;
            $lines[] = $this->dottedLine('orbit-caddy out', $this->formatMs($duration).'ms', indent: 2);
        }

        if ($accountedMs > 0.0) {
            $lines[] = $this->dottedLine(
                'Transport',
                $this->formatMs(max(0.0, $waitingMs - $accountedMs)).'ms',
                indent: 2,
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $queries
     */
    private function toolbarQuerySummary(array $queries): ?string
    {
        $count = (int) ($queries['count'] ?? 0);

        if ($count <= 0) {
            return null;
        }

        $parts = ["{$count} queries"];
        $slowCount = (int) ($queries['slow_count'] ?? 0);
        $duplicateCount = (int) ($queries['duplicate_count'] ?? 0);

        if ($slowCount > 0) {
            $parts[] = "{$slowCount} slow";
        }

        if ($duplicateCount > 0) {
            $parts[] = "{$duplicateCount} duplicate";
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function numericValue(array $values, string $key): ?float
    {
        return is_numeric($values[$key] ?? null) ? (float) $values[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function stageDurationMs(array $stage): ?float
    {
        if (is_numeric($stage['duration_ms'] ?? null)) {
            return (float) $stage['duration_ms'];
        }

        if (is_numeric($stage['duration'] ?? null)) {
            return (float) $stage['duration'];
        }

        if (is_string($stage['duration'] ?? null)) {
            $duration = rtrim($stage['duration'], 'ms');

            return is_numeric($duration) ? (float) $duration : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerFloat(array $headers, string $name): ?float
    {
        $value =
            $headers[$name] ?? $headers[strtolower($name)] ?? $headers[mb_convert_case($name, MB_CASE_TITLE)] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $timings
     */
    private function timingMs(array $timings, string $key): float
    {
        return is_numeric($timings[$key] ?? null) ? (float) $timings[$key] : 0.0;
    }

    private function formatMs(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2).'MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).'KB';
        }

        return "{$bytes}B";
    }

    private function dottedLine(string $label, string $value, int $indent = 0, string $suffix = ''): string
    {
        $prefix = str_repeat('  ', $indent);
        $labelWithPrefix = "{$prefix}{$label} ";
        $valuePart = " {$value}";
        $dotsNeeded = max(1, self::LINE_WIDTH - mb_strlen($labelWithPrefix) - mb_strlen($valuePart));

        return $labelWithPrefix.str_repeat('.', $dotsNeeded).$valuePart.$suffix;
    }
}
