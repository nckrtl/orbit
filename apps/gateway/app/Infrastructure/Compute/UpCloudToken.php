<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;

final readonly class UpCloudToken
{
    public function read(): string
    {
        $path = config('compute.upcloud.token_file');
        if (! is_string($path) || ! str_starts_with($path, '/') || is_link($path)) {
            throw $this->unavailable();
        }
        $stat = @stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['uid'] !== posix_geteuid() || $stat['size'] > 65536) {
            throw $this->unavailable();
        }
        $text = @file_get_contents($path);
        if (! is_string($text) || preg_match_all('/^token:\s*[\'\"]?(ucat_[A-Za-z0-9_-]+)[\'\"]?\s*(?:#.*)?$/m', $text, $matches) !== 1) {
            throw $this->unavailable();
        }

        return $matches[1][0];
    }

    private function unavailable(): ComputeException
    {
        return new ComputeException('compute.credential_unavailable', 'UpCloud needs a private Gateway-owned token file.');
    }
}
