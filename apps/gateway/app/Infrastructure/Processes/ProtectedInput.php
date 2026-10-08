<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class ProtectedInput
{
    /** @var resource|null */
    private mixed $stream;

    /** @var resource|null */
    private mixed $hold = null;

    /** @param resource $stream */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function fromString(#[SensitiveParameter] string $contents): self
    {
        $stream = tmpfile();

        if ($stream === false) {
            throw new RuntimeException('Unable to create protected process input.');
        }

        $input = new self($stream);

        try {
            $metadata = stream_get_meta_data($stream);
            $path = $metadata['uri'] ?? null;

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to protect process input.');
            }

            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException('Unable to protect process input.');
            }

            $length = strlen($contents);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite($stream, substr($contents, $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write protected process input.');
                }

                $offset += $written;
            }
        } catch (Throwable $exception) {
            $input->close();

            throw $exception;
        }

        return $input;
    }

    /** Copy bounded file input into the same private spool used for secrets. */
    public static function fromFile(string $path, string $prefix, int $maxBytes): self
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException('Unable to read protected process input.');
        }
        $input = self::fromString($prefix);
        try {
            $destination = $input->stream();
            if (fseek($destination, 0, SEEK_END) !== 0) {
                throw new RuntimeException('Unable to append protected process input.');
            }
            $copied = stream_copy_to_stream($source, $destination, $maxBytes + 1);
            if ($copied === false || $copied > $maxBytes || ! feof($source)) {
                throw new RuntimeException('Protected process input exceeds its limit.');
            }
        } catch (Throwable $exception) {
            $input->close();
            throw $exception;
        } finally {
            fclose($source);
        }

        return $input;
    }

    public static function holdOpen(): self
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            throw new RuntimeException('Unable to create protected process input.');
        }

        $input = new self($pair[0]);
        $input->hold = $pair[1];

        return $input;
    }

    /** @return resource */
    public function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw new LogicException('Protected process input is closed.');
        }

        $metadata = stream_get_meta_data($this->stream);

        if ($metadata['seekable'] && ! rewind($this->stream)) {
            throw new RuntimeException('Unable to read protected process input.');
        }

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->hold)) {
            fclose($this->hold);
            $this->hold = null;
        }

        if (! is_resource($this->stream)) {
            return;
        }

        fclose($this->stream);
        $this->stream = null;
    }

    /** @return array{input: string} */
    public function __debugInfo(): array
    {
        return ['input' => '[PROTECTED]'];
    }
}
