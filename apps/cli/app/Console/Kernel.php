<?php

declare(strict_types=1);

namespace App\Console;

use LaravelZero\Framework\Kernel as BaseKernel;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Kernel extends BaseKernel
{
    /**
     * Reject an unknown command instead of proxying it to the default command.
     *
     * Laravel Zero routes any unknown first argument to the default summary
     * command, which prints the command list and exits 0. Scripts and proof
     * plans must not pass by accident, so an unknown command fails here.
     *
     * @param InputInterface $input
     * @param OutputInterface|null $output
     */
    public function handle($input, $output = null): int
    {
        $commandName = $input->getFirstArgument();
        $output ??= new ConsoleOutput;

        if ($commandName !== null && ! $this->commandExists($commandName)) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln(sprintf(
                'Command "%s" is not defined. Run "orbit list" to see available commands.',
                $commandName,
            ));

            return 1;
        }

        return parent::handle($input, $output);
    }

    private function commandExists(string $commandName): bool
    {
        $this->bootstrap();

        try {
            $this->getArtisan()->find($commandName);
        } catch (CommandNotFoundException) {
            return false;
        }

        return true;
    }
}
