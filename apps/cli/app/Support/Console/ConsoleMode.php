<?php

declare(strict_types=1);

namespace App\Support\Console;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;

final readonly class ConsoleMode
{
    public function __construct(
        public bool $machine,
        public bool $mayPrompt,
        public bool $decorated,
        public bool $mayRepaint,
        public int $columns,
    ) {}

    public static function detect(
        InputInterface $input,
        OutputInterface $output,
        bool $machine = false,
        ?bool $stdinTty = null,
        ?bool $outputTty = null,
        ?int $columns = null,
    ): self {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= defined('STDIN') ? STDIN : null;
        $stdinTty ??= is_resource($stream) && stream_isatty($stream);
        $outputStream = self::outputStream($output);
        $outputTty ??= is_resource($outputStream) && stream_isatty($outputStream);
        $decorated = ! $machine && $outputTty && $output->isDecorated();

        return new self(
            machine: $machine,
            mayPrompt: ! $machine && $stdinTty && $input->isInteractive()
                && ! $input->hasParameterOption(['--no-interaction', '-n'], true),
            decorated: $decorated,
            mayRepaint: $decorated && function_exists('proc_open') && function_exists('pcntl_signal_get_handler'),
            columns: max(1, $columns ?? self::outputColumns($outputStream) ?? (new Terminal)->getWidth()),
        );
    }

    /** @param resource|null $stream */
    private static function outputColumns(mixed $stream): ?int
    {
        if (! is_resource($stream) || ! stream_isatty($stream) || PHP_OS_FAMILY === 'Windows' || ! function_exists('proc_open')) {
            return null;
        }

        $process = @proc_open(['stty', 'size'], [0 => $stream, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            return null;
        }

        try {
            $size = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
            $status = proc_close($process);
        }

        if ($status !== 0 || ! is_string($size) || preg_match('/\A\s*[0-9]+\s+([0-9]+)\s*\z/D', $size, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] > 0 ? (int) $matches[1] : null;
    }

    /** @return resource|null */
    public static function outputStream(OutputInterface $output): mixed
    {
        while ($output instanceof OutputStyle) {
            $output = $output->getOutput();
        }

        return $output instanceof StreamOutput ? $output->getStream() : null;
    }
}
