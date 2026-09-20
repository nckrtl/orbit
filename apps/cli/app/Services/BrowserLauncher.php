<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Opens a URL in the operator's browser. A command always prints the URL too, so a machine without a
 * browser, or a launcher that fails, costs the operator nothing.
 */
class BrowserLauncher
{
    public function open(string $url): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Linux' => ['xdg-open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => null,
        };

        if ($command === null) {
            return false;
        }

        try {
            $process = new Process($command);
            $process->setTimeout(10.0);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
