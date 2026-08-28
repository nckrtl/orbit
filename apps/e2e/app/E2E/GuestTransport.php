<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;

interface GuestTransport
{
    public function exec(string $instance, GuestCommand $command): GuestCommandResult;

    public function pushFile(string $instance, string $source, string $destination): void;
}
