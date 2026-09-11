<?php

declare(strict_types=1);

namespace App\Http\Streaming;

final readonly class NativeDeploymentStreamConnection implements DeploymentStreamConnection
{
    public function disconnected(): bool
    {
        return connection_aborted() !== 0;
    }

    public function send(string $line): void
    {
        echo $line;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
