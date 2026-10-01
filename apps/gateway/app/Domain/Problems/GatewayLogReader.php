<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use RuntimeException;

final readonly class LogChunk
{
    /** @param list<string> $records */
    public function __construct(
        public array $records,
        public int $offset,
    ) {}
}

/**
 * Reads at most 1 MiB of a log, stopping on a record boundary.
 * A single record may run past that budget so the next run does not start in the middle of it.
 */
final readonly class GatewayLogReader
{
    public const int Budget = 1_048_576;

    public const int RecordCap = 8_388_608;

    public function __construct(private ProblemLogParser $parser) {}

    public function read(string $path, int $offset): LogChunk
    {
        $size = $this->size($path);

        if ($offset >= $size) {
            return new LogChunk([], $offset);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The log file could not be opened.');
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new RuntimeException('The log file could not be read.');
            }

            $data = $this->pull($handle, self::Budget);
            $end = feof($handle);
            $sliced = $this->parser->slice($data, $end);

            if ($sliced['consumed'] === 0 && $data !== '' && ! $end) {
                $data .= $this->pull($handle, self::RecordCap - strlen($data));
                $sliced = $this->parser->slice($data, feof($handle));

                if ($sliced['consumed'] === 0) {
                    $sliced = ['records' => [$data], 'consumed' => strlen($data)];
                }
            }

            return new LogChunk($sliced['records'], $offset + $sliced['consumed']);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function pull($handle, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $data = '';

        while (strlen($data) < $bytes && ! feof($handle)) {
            $remaining = $bytes - strlen($data);

            if ($remaining < 1) {
                break;
            }

            $piece = fread($handle, $remaining);

            if ($piece === false) {
                throw new RuntimeException('The log file could not be read.');
            }

            if ($piece === '') {
                break;
            }

            $data .= $piece;
        }

        return $data;
    }

    private function size(string $path): int
    {
        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException('The log file could not be read.');
        }

        return $size;
    }
}
