<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Illuminate\Support\Facades\Process;

/**
 * Downloads a release asset with `curl`, as the Gateway's agent converge does on a Node. The system `curl`
 * uses the system trust store, which the static PHP of a standalone binary does not always find.
 */
final readonly class ReleaseDownloader
{
    private const int ConnectSeconds = 20;

    private const int DownloadSeconds = 120;

    /** @throws SelfUpdateFailure */
    public function download(string $url, string $path, string $errorPrefix = 'self_update'): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'The release URL is not HTTPS.');
        }

        $result = Process::timeout(self::DownloadSeconds + 30)->run([
            'curl', '--fail', '--location', '--silent', '--show-error', '--proto', '=https', '--proto-redir', '=https',
            '--connect-timeout', (string) self::ConnectSeconds, '--max-time', (string) self::DownloadSeconds,
            '--output', $path, '--', $url,
        ]);

        if (! $result->successful() || ! is_file($path)) {
            @unlink($path);

            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'Could not download '.basename($url).'.');
        }
    }
}
