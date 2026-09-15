<?php

declare(strict_types=1);

namespace App\Support\Console;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleWriter
{
    public static function write(OutputInterface $output, string $text): void
    {
        Animation::withoutRepainting(static function () use ($output, $text): void {
            self::writeRaw($output, $text);
        });
    }

    private static function writeRaw(OutputInterface $output, string $text): void
    {
        if ($output->isQuiet() || $text === '') {
            return;
        }

        $stream = ConsoleMode::outputStream($output);

        if (! is_resource($stream)) {
            $lines = explode("\n", $text);
            $tail = array_pop($lines);

            foreach ($lines as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            if ($tail !== '') {
                $output->write($tail, false, OutputInterface::OUTPUT_RAW);
            }

            return;
        }

        while ($text !== '') {
            $written = @fwrite($stream, $text);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write command output.');
            }

            $text = substr($text, $written);
        }

        if (! @fflush($stream)) {
            throw new RuntimeException('Could not flush command output.');
        }
    }
}
