<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;

interface GuestTransport
{
    public function exec(string $instance, GuestCommand $command): GuestCommandResult;

    /**
     * @param array<string, array{instance:string, command:GuestCommand}> $commands
     * @return array<string, GuestCommandResult>
     */
    public function execAll(array $commands): array;

    public function pushFile(string $instance, string $source, string $destination): void;

    /** @param array<string, array{instance:string, source:string, destination:string}> $files */
    public function pushFiles(array $files): void;
}
