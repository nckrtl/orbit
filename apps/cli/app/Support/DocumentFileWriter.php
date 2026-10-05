<?php

declare(strict_types=1);

namespace App\Support;

use ErrorException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

final readonly class DocumentFileWriter
{
    public function __construct(private Filesystem $files) {}

    public function write(string $path, #[\SensitiveParameter] string $bytes): void
    {
        $temp = null;
        try {
            if (file_exists($path) || is_link($path)) {
                throw new InvalidArgumentException('Download never overwrites an existing path.');
            }
            $directory = realpath(dirname($path));
            if ($directory === false || ! $this->files->isDirectory($directory) || ! $this->files->isWritable($directory)) {
                throw new RuntimeException('Cannot create the download file.');
            }
            $temp = @tempnam($directory, '.orbit-document-');
            if ($temp === false || realpath(dirname($temp)) !== $directory) {
                throw new RuntimeException('Cannot create the download file.');
            }
            if (! @$this->files->chmod($temp, 0600)) {
                throw new RuntimeException('Cannot make the download file private.');
            }
            if (@$this->files->put($temp, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot write the download file.');
            }
            // Hard-link publication is atomic and refuses an existing destination, including a racing creator.
            if (! @link($temp, $path)) {
                throw new RuntimeException('Cannot publish download without overwriting.');
            }
        } catch (ErrorException) {
            // Framework warning handlers may throw even for suppressed filesystem warnings.
            throw new RuntimeException('Cannot write the download file.');
        } finally {
            if (is_string($temp)) {
                try {
                    if (! @unlink($temp)) {
                        throw new RuntimeException('Cannot clean up the temporary download file.');
                    }
                } catch (ErrorException) {
                    throw new RuntimeException('Cannot clean up the temporary download file.');
                }
            }
        }
    }
}
