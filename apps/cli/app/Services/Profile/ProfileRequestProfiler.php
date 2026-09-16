<?php

declare(strict_types=1);

namespace App\Services\Profile;

interface ProfileRequestProfiler
{
    /**
     * @param  array<string, string>  $headers
     * @return array{
     *     request: array{
     *         method: string,
     *         url: string,
     *         uri: string,
     *         status: int|null,
     *         bytes: int,
     *         completed: bool,
     *         effective_url?: string
     *     },
     *     timings: array{
     *         dns_ms: float,
     *         connect_ms: float,
     *         tls_ms: float,
     *         ttfb_ms: float,
     *         download_ms: float,
     *         total_ms: float
     *     },
     *     error: array{message: string}|null,
     *     response_headers: array<string, string>
     * }
     */
    public function profile(string $url, array $headers = []): array;
}
