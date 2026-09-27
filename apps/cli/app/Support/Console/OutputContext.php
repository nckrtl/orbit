<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class OutputContext
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public static function run(InputInterface $input, OutputInterface $output, Closure $operation): mixed
    {
        $streams = $output instanceof ConsoleOutputInterface ? [$output, $output->getErrorOutput()] : [$output];
        $saved = [];

        foreach ($streams as $stream) {
            $saved[] = [$stream, $stream->getFormatter(), $stream->getVerbosity()];
        }

        $interactive = $input->isInteractive();
        $machine = $input->hasParameterOption('--json', true);

        try {
            foreach ($saved as [$stream, $formatter]) {
                $resource = ConsoleMode::outputStream($stream);
                $stream->setFormatter(new InvocationFormatter(clone $formatter, ! $machine && is_resource($resource) && stream_isatty($resource)));
            }

            return $operation();
        } finally {
            foreach ($saved as [$stream, $formatter, $verbosity]) {
                $stream->setFormatter($formatter);
                $stream->setVerbosity($verbosity);
            }

            $input->setInteractive($interactive);
        }
    }
}
