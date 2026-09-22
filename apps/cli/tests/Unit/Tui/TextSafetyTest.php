<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use App\Support\Tui\Action;
use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\NodeFormState;
use App\Support\Tui\Prompts\AnsiLine;
use App\Support\Tui\Prompts\PanelMultiSelectPrompt;
use App\Support\Tui\Prompts\PanelTextPrompt;
use App\Support\Tui\Screen;
use App\Support\Tui\State;
use App\Support\Tui\UiState;
use Laravel\Prompts\Key;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentEvent;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use PhpTui\Term\EventProvider\AggregateEventProvider;
use PhpTui\Term\InformationProvider\ClosureInformationProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Painter\AnsiPainter;
use PhpTui\Term\RawMode\TestRawMode;
use PhpTui\Term\Terminal;
use PhpTui\Term\TerminalInformation\Size;
use PhpTui\Term\Writer;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\DisplayBuilder;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{bytes: string, text: string, writes: list<string>} */
function tui_terminal_bytes(State $state, UiState $ui, string $header = '', string $footer = ''): array
{
    $writer = new class implements Writer
    {
        /** @var list<string> */
        public array $writes = [];

        public function write(string $bytes): void
        {
            $this->writes[] = $bytes;
        }
    };
    $terminal = Terminal::new(
        painter: AnsiPainter::new($writer),
        infoProvider: ClosureInformationProvider::new(static fn (): Size => new Size(50, 240)),
        eventProvider: new AggregateEventProvider([]),
        rawMode: new TestRawMode,
    );
    $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
    $display->draw(new Screen()->screen($state, $ui, $header, $footer, $display->viewportArea()));
    $bytes = implode('', $writer->writes);

    return ['bytes' => $bytes, 'text' => preg_replace('/\x1b\[[0-9;?]*[Hm]/', '', $bytes), 'writes' => $writer->writes];
}

/** @param array{bytes: string, text: string, writes: list<string>} $rendered */
function expect_safe_tui_bytes(array $rendered): void
{
    expect($rendered['bytes'])->toContain("\x1b[0m")
        ->and(mb_check_encoding($rendered['bytes'], 'UTF-8'))->toBeTrue();
    $unsafe = array_filter($rendered['writes'], static function (string $write): bool {
        // Renderer-owned cursor and SGR actions are whole writes. An input ESC is a cell write.
        if (preg_match('/\A\x1b\[[0-9;?]*[Hm]\z/', $write) === 1) {
            return false;
        }

        return preg_match('/[\p{Cc}\x{061c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $write) !== 0;
    });
    expect(array_values($unsafe))->toBe([]);
}

beforeEach(function (): void {
    $originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=240');
    $this->beforeApplicationDestroyed(static function () use ($originalColumns): void {
        putenv($originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$originalColumns);
    });
});

describe('TUI terminal text boundary', function (): void {
    it('keeps data inert through the real terminal backend', function (string $surface, string $payload): void {
        $state = tui_test_state();
        $ui = new UiState;
        $header = '';
        $footer = '';

        switch ($surface) {
            case 'header':
                $header = $payload;
                break;
            case 'footer':
                $footer = $payload;
                break;
            case 'record title':
                $state->processes[0]['name'] = $payload;
                $ui->open('processes', $state->processes[0]);
                break;
            case 'linked property':
                $state->nodes[0]['name'] = $payload;
                $ui->open('processes', $state->processes[0]);
                break;
            case 'filter':
                $ui->goTo('processes');
                $ui->filters['node'] = $payload;
                break;
            case 'metrics title':
                $state->nodes[0]['name'] = $payload;
                $ui->open('nodes', $state->nodes[0]);
                break;
            case 'metrics uptime':
                $state->nodeSamples[1] = ['cores' => [0.1], 'mem' => [1.0, 8.0], 'swap' => [0.0, 2.0], 'uptime' => $payload, 'disks' => [['/', 1.0, 8.0]]];
                $ui->open('nodes', $state->nodes[0]);
                break;
            case 'menu':
                $ui->menu = ['kind' => 'nodes', 'title' => $payload, 'target' => ['id' => 1], 'actions' => ['check' => Action::real($payload)], 'selected' => 0, 'confirm' => null, 'at' => null];
                break;
            case 'form error':
                $ui->form = new NodeFormState;
                $ui->form->error = $payload;
                break;
        }

        $rendered = tui_terminal_bytes($state, $ui, $header, $footer);
        expect_safe_tui_bytes($rendered);
        // Unchanged blank cells are cursor moves, not text writes; compare the printed content.
        expect(str_replace(' ', '', $rendered['text']))->toContain(str_replace(' ', '', TerminalText::safe($payload)));
    })->with(['header', 'footer', 'record title', 'linked property', 'filter', 'metrics title', 'metrics uptime', 'menu', 'form error'])
        ->with([
            'CSI' => "A\x1b[2JZ",
            'SGR' => "A\x1b[31mZ\x1b[0m",
            'OSC' => "A\x1b]52;c;x\x07Z",
            'C0 and C1' => "A\0\u{009b}\u{009d}Z",
            'line controls' => "A\r\t\nZ",
            'bidi' => "A\u{202e}Z",
            'invalid UTF-8' => "A\xffZ",
            'Unicode' => 'café 東京',
        ]);

    it('keeps Process and Schedule log lines raw until presentation', function (string $kind): void {
        $state = tui_test_state();
        $ui = new UiState;
        $payload = "first\nA\x1b[2J\r\t\u{2066}\xffZ\nlast café 東京\n";
        $send = static fn (): object => $kind === 'processes'
            ? new ProcessLogsResponse(1, 'horizon', 3, $payload, 'r')
            : ScheduleLogsResponse::fromGatewayData(['id' => $state->schedules[0]['id'], 'name' => 'backup', 'lines' => 3, 'output' => $payload, 'truncated' => false], 'r');
        $ui->goTo($kind);
        render_top_screen($ui, $state);
        $ui->focus = 'list';
        new Interaction($state, $ui, new ActionRunner($send), $send)->handleKey(KeyCode::Enter);
        $lines = $state->{$kind === 'processes' ? 'processLogs' : 'scheduleLogs'}[$state->{$kind}[0]['id']];

        expect($lines)->toBe(explode("\n", rtrim($payload, "\n")));
        $rendered = tui_terminal_bytes($state, $ui);
        expect_safe_tui_bytes($rendered);
        foreach ($lines as $line) {
            expect(str_replace(' ', '', $rendered['text']))->toContain(str_replace(' ', '', TerminalText::safe($line)));
        }
    })->with(['processes', 'schedules']);

    it('escapes decoded deployment bytes without changing event order or the visible tail', function (): void {
        $state = tui_test_state();
        $ui = new UiState;
        $payload = "A\x1b]52;c;x\x07\r\t\u{202e}\xffZ\nsecond café 東京\n";
        $events = [
            new AppInstanceDeploymentEvent('phase', 'before_activation', "step\x1b[2J", null, null),
            AppInstanceDeploymentEvent::fromArray(['type' => 'output', 'stream' => 'stdout', 'value_base64' => base64_encode($payload)]),
            new AppInstanceDeploymentEvent('output_truncated', null, null, null, null),
        ];
        $lines = new ReflectionMethod(Interaction::class, 'deploymentLogLines')->invoke(null, $events);
        $deployment = ['id' => 7, 'release' => 'release-7', 'branch' => 'main', 'commit' => 'a', 'started' => 'today', 'finished' => 'today', 'duration' => '1s', 'status' => 'failed', 'failed_step' => 'prepare', 'error_code' => null, 'selected_release' => null, 'by' => 'audit'];
        $ui->open('deployments', $deployment);
        $state->deploymentLogs[7] = ['old hidden line', ...array_fill(0, 60, 'older output'), ...$lines];

        expect($events[1]->value)->toBe($payload)
            ->and($lines)->toBe(["== before activation: step\x1b[2J ==", "stdout: A\x1b]52;c;x\x07\r\t\u{202e}\xffZ", 'stdout: second café 東京', '[output truncated]']);
        $rendered = tui_terminal_bytes($state, $ui);
        expect_safe_tui_bytes($rendered);
        $previous = -1;
        foreach ($lines as $line) {
            $position = strpos(str_replace(' ', '', $rendered['text']), str_replace(' ', '', TerminalText::safe($line)));
            expect($position)->not->toBeFalse()->toBeGreaterThan($previous);
            $previous = $position;
        }
        expect($rendered['text'])->not->toContain('old hidden line');
    });

    it('escapes editable prompt data before trusted ANSI parsing without changing values or cursor edits', function (): void {
        $state = tui_test_state();
        $ui = new UiState;
        $ui->form = new NodeFormState;
        $prompt = $ui->form->prompts[0][1];
        $value = "a\u{202e}b";
        foreach (mb_str_split($value) as $char) {
            $prompt->press($char);
        }
        $prompt->label = "label\x1b[31m";
        $prompt->hint = "hint\x1b]52;c;x\x07";
        $prompt->press(Key::LEFT);

        $rendered = tui_terminal_bytes($state, $ui);
        expect_safe_tui_bytes($rendered);
        expect($rendered['text'])->toContain('a\\u{202E}b', 'label\\u{001B}[31m', 'hint\\u{001B}]52;c;x\\u{0007}')
            ->and($rendered['bytes'])->toContain("\x1b[7m")
            ->and($prompt->frame())->toContain("\x1b[7mb\x1b[27m")
            ->and($prompt->value())->toBe($value);
        $prompt->press('X');
        expect($prompt->value())->toBe("a\u{202e}Xb");
    });

    it('does not interpret typed SGR or OSC as form styles in any prompt state', function (string $stateName): void {
        new NodeFormState;
        $payload = "A\x1b[31m\x1b]52;c;x\x07Z";
        $prompt = PanelTextPrompt::make(label: 'Test', default: $payload);
        $prompt->state = $stateName;
        $prompt->error = "error\r\t\u{2066}";
        $prompt->cancelMessage = "cancel\x1b[2J";
        $frame = $prompt->frame();
        $plain = TerminalText::plain($frame);

        expect($plain)->toContain('A\\u{001B}[31m\\u{001B}]52;c;x\\u{0007}Z')
            ->and($prompt->value())->toBe($payload);
        expect($plain)->not->toContain("\x1b]");
        expect($plain)->not->toContain("\x07");
    })->with(['active', 'submit', 'error', 'cancel']);

    it('escapes multiselect labels while preserving the selected values', function (bool $list): void {
        new NodeFormState;
        $payload = "role\x1b[31m\u{202e}";
        $value = $list ? $payload : 'role-id';
        $prompt = PanelMultiSelectPrompt::make(label: 'Roles', options: $list ? [$payload] : [$value => $payload], default: [$value], info: fn (): string => "info\x1b[2J");
        foreach (['active', 'submit'] as $stateName) {
            $prompt->state = $stateName;
            expect(TerminalText::plain($prompt->frame()))->toContain('role\\u{001B}[31m\\u{202E}')
                ->and($prompt->value())->toBe([$value]);
        }
    })->with([true, false]);

    it('keeps selected list options distinct when their escaped labels match', function (): void {
        new NodeFormState;
        $raw = "role\x1b";
        $literal = 'role\\u{001B}';
        $prompt = PanelMultiSelectPrompt::make(label: 'Roles', options: [$raw, $literal], default: [$raw]);

        expect(substr_count(TerminalText::plain($prompt->frame()), '◼'))->toBe(1)
            ->and($prompt->value())->toBe([$raw])
            ->and($prompt->options)->toBe([$raw, $literal]);
        $prompt->press(Key::DOWN);
        $prompt->press(Key::SPACE);
        expect($prompt->value())->toBe([$raw, $literal]);
    });

    it('uses raw text for validation and does not accumulate display escapes', function (): void {
        new NodeFormState;
        $raw = "café\u{202e}\x1b[31m";
        $validated = null;
        $prompt = PanelTextPrompt::make(label: 'Value', default: $raw, validate: function (string $value) use (&$validated): ?string {
            $validated = $value;

            return null;
        });

        expect($prompt->frame())->toBe($prompt->frame());
        $prompt->press(Key::ENTER);
        expect($prompt->done())->toBeTrue()->and($validated)->toBe($raw)->and($prompt->value())->toBe($raw);
    });

    it('keeps renderer SGR styles but escapes all other bytes in ANSI text fragments', function (): void {
        $line = AnsiLine::parse("\x1b[36msafe\x1b[0m\x1b]52;c;x\x07\r\u{202e}\xff");
        $text = implode('', array_map(static fn ($span): string => $span->content, iterator_to_array($line)));
        expect($text)->toBe('safe\\u{001B}]52;c;x\\u{0007}\\r\\u{202E}'.TerminalText::safe("\xff"))
            ->and($line->spans[0]->style->fg)->toBe(AnsiColor::Cyan);
    });
});
