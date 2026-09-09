<?php

declare(strict_types=1);

namespace App\Infrastructure\Files;

use RuntimeException;

final readonly class ProtectedFileWriter
{
    public function put(string $path, string $contents, int $permissions = 0o600): void
    {
        $directory = dirname($path);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException("Could not create protected directory [{$directory}].");
        }

        chmod(filename: $directory, permissions: 0o700);
        $candidatePath = tempnam(
            directory: $directory,
            prefix: basename($path).'.candidate.',
        );

        if ($candidatePath === false) {
            throw new RuntimeException("Could not create protected file candidate [{$path}].");
        }

        try {
            if (realpath(dirname($candidatePath)) !== realpath($directory)) {
                throw new RuntimeException("Could not create protected file candidate [{$path}].");
            }

            $written = file_put_contents($candidatePath, $contents, LOCK_EX);

            if ($written !== strlen($contents)) {
                throw new RuntimeException("Could not write protected file [{$candidatePath}].");
            }

            if (! chmod(filename: $candidatePath, permissions: $permissions)) {
                throw new RuntimeException("Could not protect file candidate [{$candidatePath}].");
            }

            if (! rename($candidatePath, $path)) {
                throw new RuntimeException("Could not install protected file [{$path}].");
            }
        } finally {
            if (is_file($candidatePath)) {
                unlink($candidatePath);
            }
        }
    }
}
