<?php

declare(strict_types=1);

use App\Support\Console\ConsoleMode;
use App\Support\Console\OutputContext;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;

describe('invocation console modes', function (): void {
    it('separates prompt admission from terminal decoration and repainting', function (): void {
        $input = new ArgvInput(['orbit', 'list']);
        $output = new BufferedOutput(decorated: true);
        $pipe = ConsoleMode::detect($input, $output, stdinTty: true, outputTty: false, columns: 60);
        $terminal = ConsoleMode::detect($input, $output, stdinTty: false, outputTty: true, columns: 120);

        expect($pipe->mayPrompt)->toBeTrue()
            ->and($pipe->decorated)->toBeFalse()
            ->and($pipe->mayRepaint)->toBeFalse()
            ->and($pipe->columns)->toBe(60)
            ->and($terminal->mayPrompt)->toBeFalse()
            ->and($terminal->mayRepaint)->toBeTrue();
    });

    it('disables prompts and decoration in machine mode even with forced ANSI', function (): void {
        $mode = ConsoleMode::detect(new ArrayInput([]), new BufferedOutput(decorated: true),
            machine: true, stdinTty: true, outputTty: true);

        expect($mode->machine)->toBeTrue()
            ->and($mode->mayPrompt)->toBeFalse()
            ->and($mode->decorated)->toBeFalse()
            ->and($mode->mayRepaint)->toBeFalse();
    });

    it('honors no-interaction before binding without treating tokens after -- as options', function (): void {
        $output = new BufferedOutput(decorated: true);
        $disabled = ConsoleMode::detect(new ArgvInput(['orbit', 'command', '-n']), $output, stdinTty: true, outputTty: true);
        $literal = ConsoleMode::detect(new ArgvInput(['orbit', 'command', '--', '--no-interaction']), $output, stdinTty: true, outputTty: true);
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        expect($disabled->mayPrompt)->toBeFalse()
            ->and($disabled->mayRepaint)->toBeTrue()
            ->and($literal->mayPrompt)->toBeTrue()
            ->and(ConsoleMode::detect($input, $output, stdinTty: true, outputTty: true)->mayPrompt)->toBeFalse();
    });

    it('uses the selected output stream and keeps undecorated terminals plain', function (): void {
        $output = new ConsoleOutput;
        expect(ConsoleMode::outputStream($output))->toBe($output->getStream())
            ->and(ConsoleMode::outputStream($output->getErrorOutput()))->toBe($output->getErrorOutput()->getStream());
        $mode = ConsoleMode::detect(new ArrayInput([]), new BufferedOutput(decorated: false), stdinTty: true, outputTty: true);
        expect($mode->mayPrompt)->toBeTrue()->and($mode->mayRepaint)->toBeFalse();
    });

    it('restores formatter identity and input state across nested command output scopes', function (): void {
        $input = new ArrayInput([]);
        $output = new BufferedOutput(decorated: true);
        $original = $output->getFormatter();

        OutputContext::run($input, $output, function () use ($input, $output): void {
            $output->setDecorated(true);
            $outer = $output->getFormatter();
            expect($output->isDecorated())->toBeFalse();
            $input->setInteractive(false);

            OutputContext::run($input, $output, function () use ($input, $output): void {
                $output->writeln('<info>literal output</info>');
                $input->setInteractive(true);
            });

            expect($output->getFormatter())->toBe($outer)->and($input->isInteractive())->toBeFalse();
        });

        expect($output->getFormatter())->toBe($original)
            ->and($output->isDecorated())->toBeTrue()
            ->and($input->isInteractive())->toBeTrue()
            ->and($output->fetch())->toBe("literal output\n");
    });

    it('keeps actual framework pipes plain while retaining list and JSON formats', function (): void {
        $launcher = dirname(__DIR__, 3).'/orbit';
        $human = new Process([PHP_BINARY, $launcher, 'list', '--ansi']);
        $human->run();
        expect($human->getExitCode())->toBe(0)
            ->and($human->getOutput())->toContain('gateway:status')
            ->not->toContain("\e")
            ->and($human->getErrorOutput())->toBe('');
        $machine = new Process([PHP_BINARY, $launcher, 'list', '--format=json', '--ansi']);
        $machine->run();
        expect($machine->getExitCode())->toBe(0)
            ->and(json_decode($machine->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('commands')
            ->and($machine->getOutput())->not->toContain("\e");
    });
});
