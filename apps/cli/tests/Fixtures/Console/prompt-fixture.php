<?php

declare(strict_types=1);

use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\InputTerminal;
use App\Support\Console\PromptAborted;
use Laravel\Prompts\PasswordPrompt;
use Laravel\Prompts\SuggestPrompt;
use Laravel\Prompts\TextPrompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Private proof fixture: php prompt-fixture.php SCENARIO MARKER [auto|plain|machine|no-interaction].
// Send disposable values over stdin. A successful prompt exclusively creates MARKER.
// Exit 0 means submitted, 20 means typed prompt abort, 64 means invalid fixture arguments.
$scenario = $argv[1] ?? '';
$marker = $argv[2] ?? '';
$format = $argv[3] ?? 'auto';

if (! in_array($scenario, ['text', 'suggest', 'select', 'empty', 'password', 'cancel', 'eof', 'read-failure'], true)
    || $marker === ''
    || ! in_array($format, ['auto', 'plain', 'machine', 'no-interaction'], true)) {
    fwrite(STDERR, "Invalid prompt fixture arguments.\n");
    exit(64);
}

$output = new StreamOutput(STDOUT, decorated: $format !== 'plain' && stream_isatty(STDOUT));
$input = new ArrayInput([]);
$input->setInteractive($format !== 'no-interaction');
$mode = ConsoleMode::detect($input, $output, machine: $format === 'machine');
$terminal = null;
$stream = null;

if ($scenario === 'eof') {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($pair === false) {
        throw new RuntimeException('Cannot create EOF fixture stream.');
    }

    [$stream, $writer] = $pair;
    fclose($writer);
    $terminal = new InputTerminal($stream, $mode->columns);
} elseif ($scenario === 'read-failure') {
    $stream = fopen('php://temp', 'w+');

    if ($stream === false) {
        throw new RuntimeException('Cannot create read-failure fixture stream.');
    }

    $terminal = new InputTerminal($stream, $mode->columns);
    fclose($stream);
}

$prompts = new CommandPrompts($mode, $output, $terminal);
$exitCode = 0;

try {
    $result = match ($scenario) {
        'text' => $prompts->run(fn (): TextPrompt => new TextPrompt(
            'Fixture name',
            validate: fn (string $value): ?string => $value === 'accepted' ? null : 'Enter accepted.',
        )),
        'suggest' => $prompts->run(fn (): SuggestPrompt => new SuggestPrompt('Fixture name', ['suggestion'])),
        'select' => $prompts->selectEntity('Choose fixture record', ['ID', 'Name'], [
            17 => ['17', 'Alpha'],
            'record-beta' => ['18', 'Beta'],
            94 => ['94', 'Gamma'],
        ]),
        'empty' => $prompts->selectEntity('Choose fixture record', ['ID', 'Name'], []),
        'password' => $prompts->run(fn (): PasswordPrompt => new PasswordPrompt('Fixture password', required: true)),
        default => $prompts->run(fn (): TextPrompt => new TextPrompt('Fixture name', default: 'unsafe-default')),
    };

    $record = json_encode(['scenario' => $scenario, 'result' => $scenario === 'password' ? 'submitted' : $result], JSON_THROW_ON_ERROR)."\n";
    $markerStream = fopen($marker, 'x');

    if ($markerStream === false) {
        throw new RuntimeException('Cannot exclusively create fixture marker.');
    }

    try {
        fwrite($markerStream, $record);
    } finally {
        fclose($markerStream);
    }

    fwrite(STDOUT, $record);
} catch (PromptAborted $exception) {
    fwrite(STDERR, json_encode(['aborted' => $exception->reason], JSON_THROW_ON_ERROR)."\n");
    $exitCode = 20;
} catch (ConsoleInterrupted $exception) {
    fwrite(STDERR, json_encode(['interrupted' => $exception->signal], JSON_THROW_ON_ERROR)."\n");
    $exitCode = $exception->getCode();
} finally {
    if (is_resource($stream)) {
        fclose($stream);
    }
}

exit($exitCode);
