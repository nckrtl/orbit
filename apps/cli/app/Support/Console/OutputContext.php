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
        $outputs = $output instanceof ConsoleOutputInterface ? [$output, $output->getErrorOutput()] : [$output];
        $saved = array_map(static fn (OutputInterface $stream): array => [$stream->getFormatter(), $stream->getVerbosity()], $outputs);
        $interactive = $input->isInteractive();
        $machine = $input->hasParameterOption('--json', true);

        try {
            foreach ($outputs as $index => $stream) {
                $resource = ConsoleMode::outputStream($stream);
                $stream->setFormatter(new InvocationFormatter(clone $saved[$index][0], ! $machine && is_resource($resource) && stream_isatty($resource)));
            }

            return $operation();
        } finally {
            foreach ($outputs as $index => $stream) {
                $stream->setFormatter($saved[$index][0]);
                $stream->setVerbosity($saved[$index][1]);
            }

            $input->setInteractive($interactive);
        }
    }
}
