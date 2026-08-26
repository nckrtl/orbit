<?php

declare(strict_types=1);

namespace App\Services\NodeSetup;

use App\Exceptions\LocalNodeSetupException;
use Closure;
use Throwable;

class ControllingTerminal
{
    /** @var Closure(): bool */
    private readonly Closure $availabilityProbe;

    /** @var Closure(): (resource|false) */
    private readonly Closure $opener;

    /**
     * @param null|(Closure(): bool) $availabilityProbe
     * @param null|(Closure(): (resource|false)) $opener
     */
    public function __construct(
        private readonly string $path = '/dev/tty',
        ?Closure $availabilityProbe = null,
        ?Closure $opener = null,
    ) {
        $this->availabilityProbe = $availabilityProbe ?? function (): bool {
            if (! is_readable($this->path) || ! is_writable($this->path)) {
                return false;
            }

            $stream = $this->openPath();

            if (! is_resource($stream)) {
                return false;
            }

            fclose($stream);

            return true;
        };
        $this->opener = $opener ?? $this->openPath(...);
    }

    public function isAvailable(): bool
    {
        try {
            return ($this->availabilityProbe)() === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return resource */
    public function open(): mixed
    {
        try {
            $stream = ($this->opener)();
        } catch (Throwable) {
            throw new LocalNodeSetupException('A controlling terminal is required.');
        }

        if (! is_resource($stream)) {
            throw new LocalNodeSetupException('A controlling terminal is required.');
        }

        return $stream;
    }

    public function confirm(string $summary): bool
    {
        $stream = $this->open();

        try {
            fwrite($stream, $summary."\nContinue? [y/N] ");
            fflush($stream);
            $response = fgets($stream);
        } finally {
            fclose($stream);
        }

        return is_string($response) && in_array(strtolower(trim($response)), ['y', 'yes'], strict: true);
    }

    /** @return resource|false */
    private function openPath(): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return fopen($this->path, 'r+b');
        } finally {
            restore_error_handler();
        }
    }
}
