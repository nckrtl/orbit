<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use Throwable;

final readonly class PrivateDnsSocketBinder
{
    public function __construct(
        private float $timeoutSeconds = 60.0,
        private float $intervalSeconds = 0.05,
    ) {}

    public function bind(PrivateDnsTransportServer $server): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            try {
                $server->start();

                return;
            } catch (Throwable $exception) {
                if (microtime(true) >= $deadline) {
                    throw $exception;
                }

                usleep((int) max(1, $this->intervalSeconds * 1_000_000));
            }
        }
    }
}
