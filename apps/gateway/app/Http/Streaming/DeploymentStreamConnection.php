<?php

declare(strict_types=1);

namespace App\Http\Streaming;

interface DeploymentStreamConnection
{
    public function disconnected(): bool;

    public function send(string $line): void;
}
