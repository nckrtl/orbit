<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\InputTerminal;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use App\Support\Console\Renderers\ConfirmRenderer;
use App\Support\Console\TerminalText;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\PasswordPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SuggestPrompt;
use Laravel\Prompts\Terminal;
use Laravel\Prompts\TextPrompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

function promptFixtureMode(bool $interactive = true, bool $machine = false, bool $decorated = true, int $columns = 80): ConsoleMode
{
    return new ConsoleMode(machine: $machine, mayPrompt: $interactive, decorated: $decorated, mayRepaint: $decorated, columns: $columns);
}

function withNativePromptFixture(Closure $operation): mixed
{
    return PromptContext::preserve(function () use ($operation): mixed {
        PromptStateFixture::native();

        return $operation();
    });
}

it('shows the complete destructive question before narrow-terminal consent', function (array $keys, bool $expected, bool $decorated): void {
    withNativePromptFixture(function () use ($keys, $expected, $decorated): void {
        $question = 'Remove Node [app-prod-2] (#8) from this Gateway?';
        $output = new BufferedOutput;
        $terminal = new PromptKeysFixtureTerminal($keys, columns: 24);
        $frames = [];
        $terminal->beforeRead = function () use ($output, &$frames): void {
            $frames[] = $output->fetch();
        };
        $prompts = new CommandPrompts(promptFixtureMode(decorated: $decorated, columns: 24), $output, $terminal);
        $result = $prompts->run(fn (): ConfirmPrompt => new ConfirmPrompt($question, default: false));
        $frames[] = $output->fetch();

        expect($result)->toBe($expected)->and($terminal->raw)->toBeFalse();

        foreach ([$frames[0], $frames[array_key_last($frames)]] as $frame) {
            $text = preg_replace('/\\x1b\\[[0-9;?]*[A-Za-z]/', '', $frame);
            $compact = preg_replace('/[\\s│]/u', '', $text);
            expect($compact)->toContain(str_replace(' ', '', $question))->not->toContain('…');

            foreach (explode("\n", $text) as $line) {
                expect(TerminalText::width($line))->toBeLessThanOrEqual(24);
            }
        }
    });
})->with([
    'default No' => [[Key::ENTER], false],
    'explicit Yes' => [['y', Key::ENTER], true],
])->with([true, false]);

it('wraps confirmation feedback without losing its explanation', function (string $state, string $label): void {
    withNativePromptFixture(function () use ($state, $label): void {
        $message = 'This operation requires consent before removing the selected Node.';
        PromptContext::run(promptFixtureMode(columns: 24), new BufferedOutput, function () use ($state, $message, $label): void {
            $prompt = new ConfirmPrompt($label, default: false, hint: $message);
            $prompt->state = $state;
            $prompt->error = $message;
            $prompt->cancelMessage = $message;
            $rendered = (new ConfirmRenderer($prompt))($prompt);
            $text = TerminalText::plain($rendered);
            $compact = preg_replace('/[\\s│⚠]/u', '', $text);

            expect($compact)->toContain(str_replace(' ', '', $message))->not->toContain('…');

            foreach (explode("\n", $text) as $line) {
                expect(TerminalText::width($line))->toBeLessThanOrEqual(24);
            }
        }, new PromptKeysFixtureTerminal([], columns: 24));
    });
})->with(['active', 'error', 'cancel'])->with(['Proceed?', 'Remove Node [app-prod-2] (#8) from this Gateway?']);

it('requires affirmative consent and stops before mutation on decline or aborted input', function (
    array $keys,
    int $status,
    string $option,
): void {
    withNativePromptFixture(function () use ($keys, $status, $option): void {
        $terminal = new PromptKeysFixtureTerminal($keys);
        $command = new class($terminal, $option) extends GatewayCommand
        {
            protected $signature = 'fixture:consent {--yes} {--force} {--json}';

            public function __construct(private readonly Terminal $terminal, private readonly string $consentOption)
            {
                parent::__construct();
            }

            protected function consoleMode(?OutputInterface $output = null): ConsoleMode
            {
                return promptFixtureMode(decorated: false);
            }

            protected function commandPrompts(): CommandPrompts
            {
                return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
            }

            public function handle(): int
            {
                if (! $this->confirmAction('Remove fixture [example]?', 'Fixture removal cancelled.', $this->consentOption)) {
                    return self::FAILURE;
                }

                $this->writeHumanMessage('Mutation admitted.');

                return self::SUCCESS;
            }
        };
        $command->setLaravel(app());
        $tester = new CommandTester($command);

        expect($tester->execute([]))->toBe($status);
        $output = $tester->getDisplay();
        expect($output)->toContain('Remove fixture [example]?')->not->toContain("\e[");
        expect($terminal->raw)->toBeFalse()->and($terminal->restores)->toBeGreaterThan(0);

        if ($status === 0) {
            expect($output)->toContain('Mutation admitted.')->not->toContain('cancelled');
        } else {
            expect($output)->toContain('Fixture removal cancelled.')->not->toContain('Mutation admitted.');
        }
    });
})->with([
    'default No' => [[Key::ENTER], 1],
    'explicit No' => [['n', Key::ENTER], 1],
    'explicit Yes' => [['y', Key::ENTER], 0],
    'Ctrl-C' => [[Key::CTRL_C], 1],
    'EOF' => [[Key::CTRL_D], 1],
])->with(['yes', 'force']);

it('refuses forbidden interaction before constructing or reading a prompt', function (bool $machine): void {
    $output = new BufferedOutput;
    $terminal = new PromptKeysFixtureTerminal([]);
    $created = false;
    $prompts = new CommandPrompts(promptFixtureMode(interactive: ! $machine ? false : true, machine: $machine), $output, $terminal);

    expect(fn () => $prompts->run(function () use (&$created): TextPrompt {
        $created = true;

        return new TextPrompt('Must not appear', default: 'must not accept');
    }))->toThrow(PromptAborted::class, 'Interactive input is not available.');
    expect($created)->toBeFalse()
        ->and($terminal->reads)->toBe(0)
        ->and($output->fetch())->toBe('');
})->with([false, true]);

it('retries invalid text and retains open suggestions in plain output', function (): void {
    withNativePromptFixture(function (): void {
        $output = new BufferedOutput;
        $terminal = new PromptKeysFixtureTerminal(['bad', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, Key::BACKSPACE, 'good', Key::ENTER]);
        $prompts = new CommandPrompts(promptFixtureMode(decorated: false), $output, $terminal);
        $result = $prompts->run(fn (): TextPrompt => new TextPrompt('Name', validate: fn (string $value): ?string => $value === 'good' ? null : 'Use a valid name.'));
        $content = $output->fetch();

        expect($result)->toBe('good')
            ->and($content)->toContain('Use a valid name.')
            ->not->toContain("\e[")
            ->and($terminal->restores)->toBeGreaterThan(0)
            ->and($terminal->raw)->toBeFalse();

        $prompts = new CommandPrompts(promptFixtureMode(), $output, new PromptKeysFixtureTerminal(['outside', Key::ENTER]));
        expect($prompts->run(fn (): SuggestPrompt => new SuggestPrompt('Name', ['suggestion'])))->toBe('outside');
    });
});

it('returns sparse stable datatable keys after filtering and after clearing an empty search', function (): void {
    withNativePromptFixture(function (): void {
        $rows = [17 => ['17', 'Alpha'], 'record-beta' => ['18', 'Beta'], 94 => ['94', 'Gamma']];
        $output = new BufferedOutput;
        $prompts = new CommandPrompts(promptFixtureMode(), $output, new PromptKeysFixtureTerminal(['Beta', Key::ENTER]));

        expect($prompts->selectEntity('Choose record', ['ID', 'Name'], $rows))->toBe('record-beta');
        expect($output->fetch())->toContain('/ Search');

        $prompts = new CommandPrompts(promptFixtureMode(), $output, new PromptKeysFixtureTerminal(['absent', Key::ENTER, Key::CTRL_U, Key::DOWN, Key::DOWN, Key::ENTER]));
        expect($prompts->selectEntity('Choose record', ['ID', 'Name'], $rows))->toBe(94);
    });
});

it('shows plain selection and search changes before accepting a stable key', function (): void {
    withNativePromptFixture(function (): void {
        $output = new BufferedOutput;
        $terminal = new PromptKeysFixtureTerminal([Key::DOWN, 'Gamma', Key::ENTER]);
        $frames = [];
        $terminal->beforeRead = function () use ($output, &$frames): void {
            $frames[] = $output->fetch();
        };
        $prompts = new CommandPrompts(promptFixtureMode(decorated: false), $output, $terminal);

        expect($prompts->selectEntity('Choose record', ['ID', 'Name'], [17 => ['17', 'Alpha'], 'record-beta' => ['18', 'Beta'], 94 => ['94', 'Gamma']]))->toBe(94);
        expect($frames[0])->toContain('Alpha', 'Beta', 'Gamma', '/ Search')
            ->and($frames[2])->toContain('/ Gamma', 'Gamma')->not->toContain('Alpha', 'Beta')
            ->and(implode('', $frames).$output->fetch())->not->toContain("\e");
    });
});

it('shows plain text edits before submission', function (): void {
    withNativePromptFixture(function (): void {
        $output = new BufferedOutput;
        $terminal = new PromptKeysFixtureTerminal(['ready', Key::BACKSPACE, 'y', Key::ENTER]);
        $frames = [];
        $terminal->beforeRead = function () use ($output, &$frames): void {
            $frames[] = $output->fetch();
        };
        $prompts = new CommandPrompts(promptFixtureMode(decorated: false), $output, $terminal);

        expect($prompts->run(fn (): TextPrompt => new TextPrompt('Name')))->toBe('ready')
            ->and($frames[1])->toContain('ready')
            ->and($frames[2])->toContain('read')->not->toContain('ready')
            ->and($frames[3])->toContain('ready')
            ->and(implode('', $frames).$output->fetch())->not->toContain("\e");
    });
});

it('cleans an interrupted prompt and restores surrounding signal handlers', function (int $signal): void {
    withNativePromptFixture(function () use ($signal): void {
        $async = pcntl_async_signals();
        $original = pcntl_signal_get_handler($signal);
        $prior = static function (): void {};
        pcntl_signal($signal, $prior);
        pcntl_async_signals(false);
        $terminal = new PromptKeysFixtureTerminal([]);
        $terminal->beforeRead = function () use ($signal): void {
            posix_kill(getmypid(), $signal);
            pcntl_signal_dispatch();
        };
        $output = new BufferedOutput;
        $mutated = false;

        try {
            $prompts = new CommandPrompts(promptFixtureMode(), $output, $terminal);
            expect(function () use ($prompts, &$mutated): void {
                $prompts->run(fn (): TextPrompt => new TextPrompt('Name'));
                $mutated = true;
            })->toThrow(ConsoleInterrupted::class);
            expect($mutated)->toBeFalse()
                ->and($terminal->raw)->toBeFalse()
                ->and($output->fetch())->toContain("\e[?25h")
                ->and(pcntl_signal_get_handler($signal))->toBe($prior)
                ->and(pcntl_async_signals())->toBeFalse();
        } finally {
            pcntl_signal($signal, $original);
            pcntl_async_signals($async);
        }
    });
})->with([SIGINT, SIGTERM]);

it('rejects an empty or structurally too narrow selector without reading input', function (): void {
    withNativePromptFixture(function (): void {
        $terminal = new PromptKeysFixtureTerminal([]);
        $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, $terminal);
        expect(fn () => $prompts->selectEntity('Choose', ['Name'], []))->toThrow(PromptAborted::class, 'No matching records');
        $prompts = new CommandPrompts(promptFixtureMode(columns: 2), new BufferedOutput, $terminal);
        expect(fn () => $prompts->selectEntity('Choose', ['Name'], [52 => ['record']]))->toThrow(PromptAborted::class, 'requires at least');
        expect($terminal->reads)->toBe(0);
    });
});

it('aborts before mutation for cancellation bytes inside buffered input', function (string $key): void {
    withNativePromptFixture(function () use ($key): void {
        $input = fopen('php://temp', 'w+');
        fwrite($input, "typed{$key}\n");
        rewind($input);
        $terminal = new PromptStreamFixtureTerminal($input);
        $output = new BufferedOutput;
        $marker = false;

        try {
            $prompts = new CommandPrompts(promptFixtureMode(), $output, $terminal);
            expect(function () use ($prompts, &$marker): void {
                $prompts->run(fn (): TextPrompt => new TextPrompt('Name', default: 'unsafe-default'));
                $marker = true;
            })->toThrow(PromptAborted::class);
            expect($marker)->toBeFalse()
                ->and($terminal->raw)->toBeFalse()
                ->and($output->fetch())->toContain("\e[?25h");
        } finally {
            fclose($input);
        }
    });
})->with([Key::CTRL_C, Key::CTRL_D]);

it('aborts a real closed input stream without accepting a default or mutating', function (): void {
    withNativePromptFixture(function (): void {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->not->toBeFalse();
        fclose($pair[1]);
        $terminal = new PromptStreamFixtureTerminal($pair[0]);
        $marker = false;

        try {
            $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, $terminal);
            expect(function () use ($prompts, &$marker): void {
                $prompts->run(fn (): TextPrompt => new TextPrompt('Name', default: 'unsafe-default'));
                $marker = true;
            })->toThrow(PromptAborted::class, 'Input ended before submission.');
            expect($marker)->toBeFalse()->and($terminal->raw)->toBeFalse();
        } finally {
            fclose($pair[0]);
        }
    });
});

it('aborts an actual input read failure and cleans the prompt terminal', function (): void {
    withNativePromptFixture(function (): void {
        $input = fopen('php://temp', 'w+');
        $terminal = new PromptStreamFixtureTerminal($input);
        fclose($input);
        $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, $terminal);

        expect(fn () => $prompts->run(fn (): TextPrompt => new TextPrompt('Name', default: 'unsafe-default')))->toThrow(PromptAborted::class, 'Unable to read input.');
        expect($terminal->raw)->toBeFalse();
    });
});

it('does not expose a password in plain or decorated prompt output', function (bool $decorated): void {
    withNativePromptFixture(function () use ($decorated): void {
        $secret = 'disposable-password-9281';
        $terminal = new PromptKeysFixtureTerminal([$secret, Key::ENTER]);
        $output = new BufferedOutput;
        $prompts = new CommandPrompts(promptFixtureMode(decorated: $decorated), $output, $terminal);

        expect($prompts->run(fn (): PasswordPrompt => new PasswordPrompt('Password')))->toBe($secret)
            ->and($output->fetch())->not->toContain($secret)
            ->and($terminal->modes)->toContain('-icanon -isig -echo')
            ->and($terminal->raw)->toBeFalse();
    });
})->with([false, true]);

it('restores context across nested sequential and failed invocations', function (): void {
    withNativePromptFixture(function (): void {
        $outer = new BufferedOutput;
        $inner = new BufferedOutput;
        $terminal = new PromptKeysFixtureTerminal([]);
        $baseline = PromptStateFixture::state();

        PromptContext::run(promptFixtureMode(), $outer, function () use ($inner, $terminal): void {
            $state = PromptStateFixture::state();
            PromptContext::run(promptFixtureMode(interactive: false, machine: true), $inner, function (): void {
                expect(PromptStateFixture::state()['interactive'])->toBeFalse();
                PromptStateFixture::changeCallbacks();
            }, $terminal);
            expect(PromptStateFixture::state())->toBe($state);
            expect(fn () => PromptContext::run(promptFixtureMode(interactive: false), $inner, fn () => throw new RuntimeException('fixture failure'), $terminal))->toThrow(RuntimeException::class, 'fixture failure');
            expect(PromptStateFixture::state())->toBe($state);
        }, $terminal);
        expect(PromptStateFixture::state())->toBe($baseline);

        PromptContext::run(promptFixtureMode(interactive: false), $inner, fn () => PromptStateFixture::changeCallbacks(), $terminal);
        expect(PromptStateFixture::state())->toBe($baseline);
    });
});

it('keeps framework validation and fallback callbacks available inside the scope', function (): void {
    withNativePromptFixture(function (): void {
        Prompt::validateUsing(fn (Prompt $prompt): ?string => $prompt->value() === 'allowed' ? null : 'Framework validation failed.');
        $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, new PromptKeysFixtureTerminal(['allowed', Key::ENTER]));
        expect($prompts->run(fn (): TextPrompt => new TextPrompt('Name', validate: 'framework-rule')))->toBe('allowed');

        $calls = 0;
        TextPrompt::fallbackUsing(function (TextPrompt $prompt) use (&$calls): string {
            $calls++;

            return 'framework-fallback';
        });
        Prompt::fallbackWhen(true);
        $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, new PromptKeysFixtureTerminal([]));
        expect($prompts->run(fn (): TextPrompt => new TextPrompt('Name')))->toBe('framework-fallback')->and($calls)->toBe(1);
    });
});

it('restores the terminal explicitly even when the prompt remains referenced', function (): void {
    withNativePromptFixture(function (): void {
        $terminal = new PromptKeysFixtureTerminal(['ready', Key::ENTER]);
        $output = new BufferedOutput;
        $prompt = new TextPrompt('Name');
        $prompts = new CommandPrompts(promptFixtureMode(), $output, $terminal);

        expect($prompts->run(fn (): TextPrompt => $prompt))->toBe('ready')
            ->and($terminal->raw)->toBeFalse()
            ->and($output->fetch())->toContain("\e[?25h");
        unset($prompt);
    });
});

it('keeps the active cursor and tty unchanged when an earlier retained prompt is destroyed', function (): void {
    withNativePromptFixture(function (): void {
        $old = new TextPrompt('Earlier name');
        $oldReference = WeakReference::create($old);
        $earlierTerminal = new PromptKeysFixtureTerminal(['earlier', Key::ENTER]);
        $earlier = new CommandPrompts(promptFixtureMode(), new BufferedOutput, $earlierTerminal);
        expect($earlier->run(fn (): TextPrompt => $old))->toBe('earlier');

        $terminal = new PromptKeysFixtureTerminal(['current', Key::ENTER]);
        $output = new BufferedOutput;
        $prompts = new CommandPrompts(promptFixtureMode(), $output, $terminal);
        $validated = false;
        $result = $prompts->run(function () use (&$old, &$validated, $oldReference, $terminal, $output): TextPrompt {
            return new TextPrompt('Current name', validate: function () use (&$old, &$validated, $oldReference, $terminal, $output): ?string {
                expect(PromptStateFixture::state()['cursor'])->toBeTrue()
                    ->and($terminal->raw)->toBeTrue()
                    ->and($terminal->restores)->toBe(0);
                $output->fetch();
                $old = null;
                $validated = true;

                expect($output->fetch())->not->toContain("\e[?25h")
                    ->and($oldReference->get())->toBeNull()
                    ->and(PromptStateFixture::state()['cursor'])->toBeTrue()
                    ->and($terminal->raw)->toBeTrue()
                    ->and($terminal->restores)->toBe(0);

                return null;
            });
        });

        expect($result)->toBe('current')
            ->and($validated)->toBeTrue()
            ->and($old)->toBeNull()
            ->and($terminal->raw)->toBeFalse()
            ->and($terminal->restores)->toBe(1)
            ->and($earlierTerminal->restores)->toBe(1)
            ->and($output->fetch())->toContain("\e[?25h");
    });
});

it('reports terminal cleanup failure without replacing an existing prompt failure', function (bool $abort): void {
    withNativePromptFixture(function () use ($abort): void {
        $baseline = PromptStateFixture::state();
        $terminal = new PromptKeysFixtureTerminal($abort ? [] : ['ready', Key::ENTER]);
        $terminal->failRestore = true;
        $output = new BufferedOutput;
        $prompts = new CommandPrompts(promptFixtureMode(), $output, $terminal);

        expect(fn () => $prompts->run(fn (): TextPrompt => new TextPrompt('Name')))
            ->toThrow($abort ? PromptAborted::class : RuntimeException::class, $abort ? 'Fixture input ended.' : 'Fixture restore failed.');
        expect($terminal->raw)->toBeFalse()
            ->and($terminal->restores)->toBe(1)
            ->and(PromptStateFixture::state())->toBe($baseline)
            ->and($output->fetch())->toContain("\e[?25h");
    });
})->with([false, true]);

it('reports cursor cleanup failure without replacing an existing prompt failure', function (bool $abort): void {
    withNativePromptFixture(function () use ($abort): void {
        $baseline = PromptStateFixture::state();
        $terminal = new PromptKeysFixtureTerminal($abort ? [] : ['ready', Key::ENTER]);
        $prompts = new CommandPrompts(promptFixtureMode(), new PromptCursorFailureOutput, $terminal);

        expect(fn () => $prompts->run(fn (): TextPrompt => new TextPrompt('Name')))
            ->toThrow($abort ? PromptAborted::class : RuntimeException::class, $abort ? 'Fixture input ended.' : 'Fixture cursor restore failed.');
        expect($terminal->raw)->toBeFalse()
            ->and(PromptStateFixture::state())->toBe($baseline);
    });
})->with([false, true]);

it('rejects a fallback default when its input stream reaches actual EOF', function (): void {
    withNativePromptFixture(function (): void {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->not->toBeFalse();
        $marker = false;

        try {
            TextPrompt::fallbackUsing(function () use ($pair): string {
                fclose($pair[1]);
                fread($pair[0], 1024);

                return 'unsafe-default';
            });
            Prompt::fallbackWhen(true);
            $terminal = new PromptStreamFixtureTerminal($pair[0]);
            $prompts = new CommandPrompts(promptFixtureMode(), new BufferedOutput, $terminal);

            expect(function () use ($prompts, &$marker): void {
                $prompts->run(fn (): TextPrompt => new TextPrompt('Name', default: 'unsafe-default'));
                $marker = true;
            })->toThrow(PromptAborted::class, 'Input ended before submission.');
            expect($marker)->toBeFalse()->and($terminal->raw)->toBeFalse();
        } finally {
            fclose($pair[0]);

            if (is_resource($pair[1])) {
                fclose($pair[1]);
            }
        }
    });
});

it('keeps an idle blocking input interruptible and restores its blocking mode', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();
    $terminal = new InputTerminal($pair[0]);

    try {
        expect(stream_get_meta_data($pair[0])['blocked'])->toBeTrue();
        $started = microtime(true);
        expect($terminal->read())->toBe('')
            ->and(microtime(true) - $started)->toBeLessThan(0.5)
            ->and(stream_get_meta_data($pair[0])['blocked'])->toBeTrue();
        fwrite($pair[1], 'ready');
        expect($terminal->read())->toBe('ready')
            ->and(stream_get_meta_data($pair[0])['blocked'])->toBeTrue();
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});

it('distinguishes temporarily empty input from EOF', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();
    stream_set_blocking($pair[0], false);
    $terminal = new InputTerminal($pair[0]);

    try {
        expect($terminal->read())->toBe('');
        fwrite($pair[1], 'ready');
        expect($terminal->read())->toBe('ready');
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});

final class PromptCursorFailureOutput extends BufferedOutput
{
    protected function doWrite(string $message, bool $newline): void
    {
        if ($message === "\e[?25h") {
            throw new RuntimeException('Fixture cursor restore failed.');
        }

        parent::doWrite($message, $newline);
    }
}

abstract class PromptStateFixture extends Prompt
{
    public static function native(): void
    {
        self::$shouldFallback = false;
        self::$validateUsing = null;
        self::$cursorHidden = false;
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        return [
            'output' => self::output(),
            'terminal' => self::terminal(),
            'interactive' => self::$interactive ?? stream_isatty(STDIN),
            'cancel' => self::$cancelUsing ?? null,
            'validate' => self::$validateUsing ?? null,
            'revert' => self::$revertUsing,
            'fallback' => self::$shouldFallback,
            'fallbacks' => self::$fallbacks,
            'theme' => self::$theme,
            'themes' => self::$themes,
            'cursor' => self::$cursorHidden,
        ];
    }

    public static function changeCallbacks(): void
    {
        self::$cancelUsing = fn (): string => 'changed';
        self::$validateUsing = fn (): string => 'changed';
        self::$revertUsing = fn (): string => 'changed';
        self::$shouldFallback = ! self::$shouldFallback;
        self::$fallbacks = [];
    }
}

final class PromptKeysFixtureTerminal extends Terminal
{
    public ?Closure $beforeRead = null;

    public bool $failRestore = false;

    public int $reads = 0;

    public int $restores = 0;

    public bool $raw = false;

    /** @var list<string> */
    public array $modes = [];

    /** @param list<string> $keys */
    public function __construct(private array $keys, private readonly int $columns = 80)
    {
        parent::__construct();
    }

    public function read(): string
    {
        $this->reads++;
        ($this->beforeRead ?? static function (): void {})();

        return array_shift($this->keys) ?? throw new PromptAborted('Fixture input ended.', 'eof');
    }

    public function setTty(string $mode): void
    {
        $this->modes[] = $mode;
        $this->raw = true;
    }

    public function restoreTty(): void
    {
        $this->restores++;
        $this->raw = false;

        if ($this->failRestore) {
            throw new RuntimeException('Fixture restore failed.');
        }
    }

    public function cols(): int
    {
        return $this->columns;
    }

    public function lines(): int
    {
        return 24;
    }

    public function initDimensions(): void {}

    public function supportsTrueColor(): bool
    {
        return false;
    }
}

final class PromptStreamFixtureTerminal extends InputTerminal
{
    public bool $raw = false;

    public function setTty(string $mode): void
    {
        $this->raw = true;
    }

    public function restoreTty(): void
    {
        $this->raw = false;
    }

    public function lines(): int
    {
        return 24;
    }

    public function initDimensions(): void {}

    public function supportsTrueColor(): bool
    {
        return false;
    }
}
