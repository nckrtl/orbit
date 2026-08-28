<?php

declare(strict_types=1);

namespace App\E2E\State;

use Closure;
use JsonException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity Atomic I/O requires every failure to be checked. */
final readonly class AtomicJsonStore
{
    public function __construct(
        private StatePaths $paths,
        private ?Closure $failure = null,
    ) {}

    /** @return array<array-key, mixed>|null */
    public function read(string $name): ?array
    {
        $file = $this->paths->path($name);

        if (! file_exists($file)) {
            return null;
        }

        if (! is_file($file) || is_link($file)) {
            throw new RuntimeException('The JSON state target is unsafe.');
        }

        $contents = file_get_contents($file);

        try {
            $value = json_decode($contents === false ? '' : $contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The JSON state is malformed.', previous: $exception);
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('The JSON state must be an object.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    public function write(string $name, array $value): void
    {
        if (array_is_list($value)) {
            throw new RuntimeException('The JSON state must be an object.');
        }

        $file = $this->paths->ensureParent($name);
        $directory = dirname($file);
        $temporary = tempnam($directory, '.state-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to create temporary state.');
        }

        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open temporary state.');
            }

            try {
                $offset = 0;

                while ($offset < strlen($json)) {
                    $written = fwrite($handle, substr($json, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write temporary state.');
                    }

                    $offset += $written;
                }

                if (! fflush($handle) || function_exists('fsync') && ! fsync($handle)) {
                    throw new RuntimeException('Unable to sync temporary state.');
                }
            } finally {
                fclose($handle);
            }

            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to protect temporary state.');
            }

            $this->failure?->__invoke('after_temporary_write', $temporary, $file);
            $validated = file_get_contents($temporary);
            json_decode($validated === false ? '' : $validated, true, 512, JSON_THROW_ON_ERROR);
            $this->failure?->__invoke('before_rename', $temporary, $file);

            if (! rename($temporary, $file)) {
                throw new RuntimeException('Unable to commit JSON state.');
            }

            $permissionResult = $this->failure?->__invoke('post_rename_chmod', $file, $file);

            if ($permissionResult === false || ! chmod($file, 0600)) {
                throw new RuntimeException('JSON state was committed but its permissions could not be protected.');
            }

            $this->syncDirectory($directory);
        } catch (JsonException $exception) {
            throw new RuntimeException('The temporary JSON state is malformed.', previous: $exception);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            return;
        }

        $handle = fopen($directory, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the state directory.');
        }

        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Unable to sync the state directory.');
            }
        } finally {
            fclose($handle);
        }
    }
}
