<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use JsonException;
use ZipArchive;

final readonly class TiaBaselineFiles
{
    public const int MaxBytes = 4 * 1024 * 1024;

    /** @param array<string, string> $files */
    private function __construct(public array $files) {}

    public static function fromArchive(string $archive, string $branch, string $sha): self
    {
        if (! class_exists(ZipArchive::class) || strlen($archive) > self::MaxBytes) {
            throw self::invalid();
        }
        $path = tempnam(sys_get_temp_dir(), 'orbit-tia-');
        if ($path === false) {
            throw self::invalid();
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            chmod($path, 0600);
            if (file_put_contents($path, $archive) !== strlen($archive) || $zip->open($path) !== true) {
                throw self::invalid();
            }
            $opened = true;
            if ($zip->numFiles < 1 || $zip->numFiles > 2) {
                throw self::invalid();
            }
            $files = [];
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw self::invalid();
                }
                $name = $stat['name'];
                $bytes += $stat['size'];
                $opsys = 0;
                $attributes = 0;
                if (! in_array($name, ['graph.json', 'js-module-graph.cache.json'], true)
                    || isset($files[$name]) || $bytes > self::MaxBytes
                    || ! $zip->getExternalAttributesIndex($index, $opsys, $attributes)
                    || (($attributes >> 16) & 0170000) === 0120000) {
                    throw self::invalid();
                }
                $contents = $zip->getFromIndex($index, self::MaxBytes + 1);
                if (! is_string($contents) || strlen($contents) !== $stat['size']) {
                    throw self::invalid();
                }
                try {
                    $decoded = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw self::invalid();
                }
                if (! is_array($decoded)) {
                    throw self::invalid();
                }
                $files[$name] = $contents;
            }
            $graph = json_decode($files['graph.json'] ?? '', true);
            $baselines = is_array($graph) ? ($graph['baselines'] ?? null) : null;
            $baseline = is_array($baselines) ? ($baselines[$branch] ?? null) : null;
            if (! is_array($baseline) || ($baseline['sha'] ?? null) !== $sha
                || ($baseline['complete'] ?? null) !== true) {
                throw self::invalid();
            }

            return new self($files);
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('instance.tia_baseline_invalid', 'The TIA baseline archive is invalid or ZIP support is unavailable.', 422);
    }
}
