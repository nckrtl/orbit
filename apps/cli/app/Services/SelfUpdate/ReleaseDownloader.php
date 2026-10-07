<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Illuminate\Support\Facades\Process;

/**
 * Downloads a release asset with `curl`, as the Gateway's agent converge does on a Node. The system `curl` uses
 * the system trust store, which the static PHP of a standalone binary does not always find. `--disable` keeps a
 * user's `.curlrc` out, and the transfer stays on HTTPS with a bounded size and number of redirects.
 */
final readonly class ReleaseDownloader
{
    public const int BinaryMaxBytes = 256 * 1024 * 1024;

    public const int ChecksumsMaxBytes = 64 * 1024;

    private const int ConnectSeconds = 20;

    private const int DownloadSeconds = 120;

    /** @throws SelfUpdateFailure */
    public function download(string $url, string $path, int $maxBytes, string $errorPrefix = 'self_update'): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'The release URL is not HTTPS.');
        }

        $result = Process::timeout(self::DownloadSeconds + 30)->run([
            'curl', '--disable', '--fail', '--location', '--silent', '--show-error',
            '--proto', '=https', '--proto-redir', '=https', '--max-redirs', '5',
            '--max-filesize', (string) $maxBytes,
            '--connect-timeout', (string) self::ConnectSeconds, '--max-time', (string) self::DownloadSeconds,
            '--output', $path, '--', $url,
        ]);

        clearstatcache(true, $path);

        if (! $result->successful() || ! is_file($path) || filesize($path) > $maxBytes) {
            @unlink($path);

            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'Could not download '.basename($url).'.');
        }
    }
}
