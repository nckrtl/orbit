<?php

declare(strict_types=1);

namespace App\Console;

use App\Support\DocumentVersionInput;
use App\Support\ExtensionCommandVisibility;
use App\Support\GatedExtensionCommand;
use Illuminate\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class OrbitConsoleApplication extends Application
{
    private bool $enumeratingCommands = false;

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if (in_array($input->getFirstArgument(), ['project:document:read', 'project:document:download', 'project:document:restore-version'], true)) {
            $input = new DocumentVersionInput($input);
        }

        return parent::doRun($input, $output);
    }

    /**
     * @return array<string, Command>
     */
    public function all(?string $namespace = null): array
    {
        $this->enumeratingCommands = true;

        try {
            $commands = parent::all($namespace);
        } finally {
            $this->enumeratingCommands = false;
        }

        if (! ExtensionCommandVisibility::listing()) {
            return $commands;
        }

        return array_filter($commands, static fn (Command $command): bool => ! self::isGatedAndHidden($command));
    }

    public function get(string $name): Command
    {
        $command = parent::get($name);

        if (
            ExtensionCommandVisibility::listing()
            && ! $this->enumeratingCommands
            && self::isGatedAndHidden($command)
        ) {
            throw new CommandNotFoundException(sprintf('The command "%s" is not available.', $name));
        }

        return $command;
    }

    private static function isGatedAndHidden(Command $command): bool
    {
        return $command instanceof GatedExtensionCommand
            && $command->extensionSlug() !== null
            && $command->isHidden();
    }
}
