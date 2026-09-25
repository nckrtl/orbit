<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Creates a private directory short enough to hold Unix socket fixtures on every test host.
 *
 * A Unix socket path must fit in sockaddr_un.sun_path: 107 bytes on Linux, 103 on macOS. The macOS
 * temporary directory alone takes about 60 bytes, so fixture sockets under sys_get_temp_dir() overflow it.
 * This directory lives directly under /tmp instead. BSD file systems give new files the directory's group, and
 * /tmp belongs to wheel on macOS, so the directory takes the test process's group as it would on Linux.
 */
final class UnixSocketDirectory
{
    public static function create(): string
    {
        $base = realpath('/tmp');

        if ($base === false) {
            throw new RuntimeException('The /tmp directory does not exist.');
        }

        $directory = $base.'/o-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0o700) || ! chgrp($directory, posix_getegid())) {
            throw new RuntimeException("Could not create the Unix socket fixture directory [{$directory}].");
        }

        return $directory;
    }
}
