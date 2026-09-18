<?php

declare(strict_types=1);

namespace App\Services\Profile;

final readonly class CurlProfileRequestProfiler implements ProfileRequestProfiler
{
    private const int DEFAULT_TIMEOUT_SECONDS = 30;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function profile(string $url, array $headers = [], ?string $caPath = null): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return $this->failedProfile($url, 'Could not initialize cURL.');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => $this->profileTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ];

        if ($caPath !== null) {
            // Assigned rather than unpacked into the literal above: array unpacking renumbers
            // integer keys, and every CURLOPT_* constant is an integer, so a spread would
            // silently drop this and leave the request verifying against the system store.
            $options[CURLOPT_CAINFO] = $caPath;
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $errorMessage = $response === false ? curl_error($handle) : null;
        $info = curl_getinfo($handle);

        if (! is_array($info)) {
            $info = [];
        }

        $completed = $response !== false;
        $request = [
            'method' => 'GET',
            'url' => $url,
            'uri' => $this->requestUri($url),
            'status' => $completed ? $this->httpStatus($info) : null,
            'bytes' => is_string($response) ? strlen($response) : 0,
            'completed' => $completed,
        ];

        $effectiveUrl = $this->effectiveUrl($info);

        if ($effectiveUrl !== null && $effectiveUrl !== $url) {
            $request['effective_url'] = $effectiveUrl;
        }

        return [
            'request' => $request,
            'timings' => $this->timingsFromCurlInfo($info),
            'error' => $errorMessage !== null ? ['message' => $errorMessage] : null,
            'response_headers' => $responseHeaders,
        ];
    }

    /**
     * @return array{
     *     request: array{method: string, url: string, uri: string, status: null, bytes: int, completed: false},
     *     timings: array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float},
     *     error: array{message: string},
     *     response_headers: array<string, string>
     * }
     */
    private function failedProfile(string $url, string $message): array
    {
        return [
            'request' => [
                'method' => 'GET',
                'url' => $url,
                'uri' => $this->requestUri($url),
                'status' => null,
                'bytes' => 0,
                'completed' => false,
            ],
            'timings' => [
                'dns_ms' => 0.0,
                'connect_ms' => 0.0,
                'tls_ms' => 0.0,
                'ttfb_ms' => 0.0,
                'download_ms' => 0.0,
                'total_ms' => 0.0,
            ],
            'error' => ['message' => $message],
            'response_headers' => [],
        ];
    }

    private function profileTimeoutSeconds(): int
    {
        return $this->timeoutSeconds > 0
            ? $this->timeoutSeconds
            : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function httpStatus(array $info): ?int
    {
        $status = (int) $this->floatInfo($info, 'http_code');

        return $status > 0 ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function effectiveUrl(array $info): ?string
    {
        $effectiveUrl = $info['url'] ?? null;

        return is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : null;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float}
     */
    private function timingsFromCurlInfo(array $info): array
    {
        return [
            'dns_ms' => $this->timingMilliseconds($info, 'namelookup'),
            'connect_ms' => $this->timingMilliseconds($info, 'connect'),
            'tls_ms' => $this->tlsMilliseconds($info),
            'ttfb_ms' => $this->timingMilliseconds($info, 'starttransfer'),
            'download_ms' => $this->durationMilliseconds($info, 'starttransfer', 'total') ?? 0.0,
            'total_ms' => $this->timingMilliseconds($info, 'total'),
        ];
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function tlsMilliseconds(array $info): float
    {
        return
            $this->durationMilliseconds($info, 'connect', 'appconnect') ?? $this->durationMilliseconds(
                $info,
                'connect',
                'pretransfer',
            ) ?? 0.0;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function timingMilliseconds(array $info, string $name): float
    {
        $microseconds = $this->numericInfo($info, "{$name}_time_us");

        if ($microseconds !== null) {
            return $this->microsecondsToMilliseconds($microseconds);
        }

        return $this->toMilliseconds($this->numericInfo($info, "{$name}_time") ?? 0.0);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function durationMilliseconds(array $info, string $start, string $end): ?float
    {
        $startMicroseconds = $this->numericInfo($info, "{$start}_time_us");
        $endMicroseconds = $this->numericInfo($info, "{$end}_time_us");

        if ($startMicroseconds !== null && $endMicroseconds !== null) {
            return $this->microsecondsToMilliseconds($endMicroseconds - $startMicroseconds);
        }

        $startSeconds = $this->numericInfo($info, "{$start}_time");
        $endSeconds = $this->numericInfo($info, "{$end}_time");

        if ($startSeconds !== null && $endSeconds !== null) {
            return $this->toMilliseconds($endSeconds - $startSeconds);
        }

        return null;
    }

    private function requestUri(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? "{$path}?{$query}" : $path;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function floatInfo(array $info, string $key): float
    {
        return $this->numericInfo($info, $key) ?? 0.0;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function numericInfo(array $info, string $key): ?float
    {
        $value = $info[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function microsecondsToMilliseconds(float $microseconds): float
    {
        return round(max(0.0, $microseconds) / 1000, 2);
    }

    private function toMilliseconds(float $seconds): float
    {
        return round(max(0.0, $seconds) * 1000, 2);
    }
}
