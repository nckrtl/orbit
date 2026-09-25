<?php

declare(strict_types=1);

use App\Support\Console\ConsoleMode;
use App\Support\Console\HumanRenderer;
use App\Support\Console\Renderers\TableLayout;
use App\Support\Console\Renderers\TableTheme;
use App\Support\Console\TerminalText;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

describe('safe human display cells', function (): void {
    it('preserves zero and false while displaying missing values separately', function (): void {
        expect(TerminalText::displayValue(0))->toBe('0')
            ->and(TerminalText::displayValue(false))->toBe('no')
            ->and(TerminalText::displayValue(true))->toBe('yes')
            ->and(TerminalText::displayValue(null))->toBe('—')
            ->and(TerminalText::displayValue(''))->toBe('—')
            ->and(TerminalText::displayValue([0, false, null]))->toBe('0, no, —');
    });

    it('makes control and bidi input visible without executing it', function (): void {
        $safe = TerminalText::safe("<info>name</info>\n\033[2J\r\u{202e}tail");

        expect($safe)->toBe('<info>name</info>\\n\\u{001B}[2J\\r\\u{202E}tail')
            ->not->toContain("\033", "\n", "\r", "\u{202e}")
            ->and(mb_check_encoding(TerminalText::safe("bad\xfftext"), 'UTF-8'))->toBeTrue();
    });

    it('wraps detail values at spaces and breaks only words longer than a line', function (): void {
        expect(TerminalText::wrapWords('Run bun install in the project root and commit bun.lock.', 20))
            ->toBe(['Run bun install in', 'the project root and', 'commit bun.lock.'])
            ->and(TerminalText::wrapWords('abcdefghij klm', 4))->toBe(['abcd', 'efgh', 'ij', 'klm'])
            ->and(TerminalText::wrapWords('', 4))->toBe(['']);

        $output = new HumanRenderer(human_layout_mode(40))->detail('JavaScript', ['Fix' => 'Run bun install in the project root and commit bun.lock.']);

        expect($output)->toContain('Run bun install in the project', 'root and commit bun.lock.')
            ->not->toContain('co'.PHP_EOL);
        human_layout_assert_width($output, 40);
    });

    it('wraps wide and combining graphemes without losing text', function (): void {
        $text = "A界e\u{0301}👩‍💻🇳🇱👍🏽1️⃣Z";
        $lines = TerminalText::wrap($text, 4);

        expect(implode('', $lines))->toBe($text)
            ->and(TerminalText::width("e\u{0301}"))->toBe(1)
            ->and(TerminalText::width('👩‍💻'))->toBe(4)
            ->and(TerminalText::width('🇳🇱'))->toBe(2)
            ->and(TerminalText::width('👍🏽'))->toBe(4)
            ->and(TerminalText::width('1️⃣'))->toBe(1)
            ->and(TerminalText::width(TerminalText::style('界', 'cyan', true)))->toBe(2);

        foreach ($lines as $line) {
            expect(TerminalText::width($line))->toBeLessThanOrEqual(4);
        }

        expect(fn () => TerminalText::wrap('界', 1))->toThrow(LengthException::class)
            ->and(fn () => TerminalText::wrap('👩‍💻', 3))->toThrow(LengthException::class)
            ->and(TerminalText::wrap('A👩‍💻B', 4))->toBe(['A', '👩‍💻', 'B'])
            ->and(TerminalText::minimumWidth(''))->toBe(1);
    });
});

describe('human result layouts', function (): void {
    it('renders the exact detail connectors spacing and missing value', function (): void {
        $renderer = new HumanRenderer(human_layout_mode());

        expect($renderer->detail('Resource: example', ['Name' => 'example', 'Status' => 'ready', 'Owner' => null]))
            ->toBe("\n┌  Resource: example\n│\n├  Name     example\n│\n├  Status   ready\n│\n└  Owner    —\n\n");
    });

    it('dims only detail connectors and leaves labels values and title at normal intensity', function (): void {
        $renderer = new HumanRenderer(human_layout_mode(decorated: true));
        $result = $renderer->detail('Resource: example', ['Name' => 'example']);

        expect($result)->toContain("\033[2m┌\033[0m  Resource: example", "\033[2m└\033[0m  Name   example")
            ->not->toContain("\033[2mName", "\033[2mexample", "\033[2mResource");
    });

    it('wraps long labels titles and values within detail width', function (): void {
        $result = new HumanRenderer(human_layout_mode(20))->detail(
            'Resource: an unusually long selector',
            ['Very long property label' => '界'.str_repeat('value', 8), 'Owner' => false],
        );

        human_layout_assert_width($result, 20);
        expect($result)->toContain('┌', '└', 'no')->not->toContain('…');
    });

    it('renders grouped properties and failure details without duplicating the error', function (): void {
        $renderer = new HumanRenderer(human_layout_mode());

        expect($renderer->properties([
            ['title' => 'Members', 'items' => [['label' => 'example', 'fields' => ['Count' => 0, 'Enabled' => false, 'Owner' => null]]]],
        ]))->toBe("Members\n  example\n    Count: 0\n    Enabled: no\n    Owner: —\n");

        $failure = $renderer->failure('Invalid configuration.', ['root' => ['Must be relative.', 'Must exist.']], 'request-123', 'input.invalid');

        expect($failure)->toBe("Invalid configuration.\nroot: Must be relative.\nroot: Must exist.\nCode: input.invalid\nRequest ID: request-123\n")
            ->and(substr_count($failure, 'Invalid configuration.'))->toBe(1);
        human_layout_assert_width(new HumanRenderer(human_layout_mode(12))->failure('An unusually long failure.', ['long field' => '<info>literal</info>'], 'request-123'), 12);
    });

    it('never renders human content in machine mode', function (): void {
        $renderer = new HumanRenderer(new ConsoleMode(machine: true, mayPrompt: false, decorated: false, mayRepaint: false, columns: 80));

        expect($renderer->detail('Title', ['Value' => 1]))->toBe('')
            ->and($renderer->table(['Value'], [[1]]))->toBe('')
            ->and($renderer->properties([['title' => 'Group', 'items' => []]]))->toBe('')
            ->and($renderer->failure('Failure'))->toBe('');
    });

    it('renders warning text plain, orange when decorated, and never in machine mode', function (): void {
        $plain = new HumanRenderer(human_layout_mode())->warning('Publication not cleaned.');
        $decorated = new HumanRenderer(human_layout_mode(decorated: true))->warning('Publication not cleaned.');
        $machine = new HumanRenderer(new ConsoleMode(machine: true, mayPrompt: false, decorated: false, mayRepaint: false, columns: 80))->warning('Publication not cleaned.');

        expect($plain)->toBe("Publication not cleaned.\n")
            ->not->toContain("\033")
            ->and($decorated)->toBe("\033[38;5;208mPublication not cleaned.\033[0m\n")
            ->and($machine)->toBe('');
    });

    it('wraps warning text within the available width', function (): void {
        $result = new HumanRenderer(human_layout_mode(20))->warning('Publication not cleaned: no single active Gateway.');

        human_layout_assert_width($result, 20);
        expect($result)->not->toContain('…');
    });
});

describe('Prompts table layout', function (): void {
    it('renders uppercase columns through the real Table primitive and preserves literal markup', function (): void {
        $result = new HumanRenderer(human_layout_mode())->table(['name', 'count', 'enabled', 'owner'], [['<info>literal</info>', 0, false, null]]);

        expect($result)->toContain('NAME', 'COUNT', 'ENABLED', 'OWNER', '<info>literal</info>', ' 0 ', ' no ', ' — ', '┌', '└')
            ->not->toContain('\\<', "\033");
        human_layout_assert_width($result, 80);
    });

    it('wraps all header and value columns at the structural minimum', function (): void {
        $headers = ['First field', 'Owner'];
        $rows = [['界abcdef', 'second-value']];
        $minimum = TableLayout::minimumWidth($headers, $rows);
        $result = new HumanRenderer(human_layout_mode($minimum))->table($headers, $rows);

        expect($minimum)->toBe(11)
            ->and($result)->toContain('┌', '└', '界')->not->toContain('…', 'Record 1');
        human_layout_assert_width($result, $minimum);

        $cells = human_layout_table_cells($result);
        expect(implode('', $cells[0]))->toContain('FIRSTFIELD', '界abcdef')
            ->and(implode('', $cells[1]))->toContain('OWNER', 'second-value');
    });

    it('uses a complete labeled record below minimum instead of hiding columns', function (): void {
        $minimum = TableLayout::minimumWidth(['Name', 'Owner'], [['界', 'Ada']]);
        $result = new HumanRenderer(human_layout_mode($minimum - 1))->table(['Name', 'Owner'], [['界', 'Ada']]);

        expect($result)->toContain('Record 1', 'NAME: 界', 'OWNER: Ada')->not->toContain('┌', '…');
        human_layout_assert_width($result, $minimum - 1);
        expect(fn () => TableLayout::minimumWidth(['Name'], [['one', 'unlabelled']]))->toThrow(InvalidArgumentException::class);
    });

    it('reports empty results and restores the enclosing prompt theme', function (): void {
        $theme = Prompt::theme();
        $renderer = new HumanRenderer(human_layout_mode());

        expect($renderer->table(['Name'], []))->toBe("No matching records found.\n");
        $renderer->table(['Name'], [['value']]);
        expect(Prompt::theme())->toBe($theme);

        TableTheme::run(human_layout_mode(60), function (): void {
            try {
                TableTheme::run(human_layout_mode(20), static fn (): never => throw new RuntimeException('fixture'));
            } catch (RuntimeException) {
                expect(TableTheme::mode()->columns)->toBe(60);
            }
        });

        expect(Prompt::theme())->toBe($theme);
    });
});

function human_layout_mode(int $columns = 80, bool $decorated = false): ConsoleMode
{
    return new ConsoleMode(machine: false, mayPrompt: false, decorated: $decorated, mayRepaint: $decorated, columns: $columns);
}

function human_layout_assert_width(string $output, int $columns): void
{
    foreach (explode(PHP_EOL, $output) as $line) {
        expect(TerminalText::width($line))->toBeLessThanOrEqual($columns);
    }
}

/** @return array<int, list<string>> */
function human_layout_table_cells(string $output): array
{
    $columns = [];

    foreach (explode(PHP_EOL, TerminalText::plain($output)) as $line) {
        if (! str_contains($line, '│')) {
            continue;
        }

        $cells = explode('│', $line);
        array_shift($cells);
        array_pop($cells);

        foreach ($cells as $index => $cell) {
            $columns[$index][] = trim($cell);
        }
    }

    return $columns;
}

final class HumanLayoutTerminal extends Terminal
{
    public function __construct(private readonly int $columns, private readonly int $rows) {}

    public function cols(): int
    {
        return $this->columns;
    }

    public function lines(): int
    {
        return $this->rows;
    }

    public function restoreTty(): void {}
}
