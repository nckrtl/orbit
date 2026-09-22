<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Console\TerminalText;
use App\Support\Tui\Prompts\PanelConfirmPrompt;
use Laravel\Prompts\Key;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Widget;

/** One default-No question and its last-drawn, bounded mouse choices. */
final class Confirmation
{
    public readonly PanelConfirmPrompt $prompt;

    public bool $fits = false;

    /** @var array<string, Area> */
    public array $buttons = [];

    public function __construct(string $question)
    {
        $this->prompt = new PanelConfirmPrompt(TerminalText::safe($question), default: false);
        $this->prompt->state = 'active';
    }

    /** Null means that the question is still open. */
    public function press(string $key): ?bool
    {
        if ($key === Key::ESCAPE) {
            return false;
        }

        if (! $this->fits) {
            return null;
        }

        $this->prompt->press(in_array($key, ['Y', 'N'], true) ? strtolower($key) : $key);

        return $this->prompt->done() ? $this->prompt->value() : null;
    }

    public function click(int $x, int $y): ?bool
    {
        foreach ($this->buttons as $choice => $area) {
            if ($x >= $area->left() && $x < $area->right() && $y >= $area->top() && $y < $area->bottom()) {
                return $choice === 'yes';
            }
        }

        return null;
    }

    public function widget(Area $area): Widget
    {
        $this->buttons = [];
        $width = max(4, min(70, $area->width - 2));
        $question = TerminalText::wrap($this->prompt->label, max(2, $width - 4));
        $hints = TerminalText::wrap('←→ or y/n choose · Enter accepts · Esc cancels', max(2, $width - 4));
        $height = count($question) + count($hints) + 5;
        $this->fits = $area->width >= 20 && $height <= $area->height;

        if (! $this->fits) {
            $question = TerminalText::wrap('Resize to read the full question. Esc cancels.', max(2, $width - 4));
            $height = min($area->height, count($question) + 2);
        }

        $left = $area->left() + max(0, intdiv($area->width - $width, 2));
        $top = $area->top() + max(0, intdiv($area->height - $height, 2));
        $lines = array_map(static fn (string $line): Line => Line::fromString(' '.$line), $question);

        if ($this->fits) {
            $lines[] = Line::fromString('');
            $row = $top + 1 + count($lines);
            $this->buttons = ['no' => Area::fromScalars($left + 2, $row, 6, 1), 'yes' => Area::fromScalars($left + 10, $row, 7, 1)];
            $selected = Style::default()->addModifier(Modifier::REVERSED)->addModifier(Modifier::BOLD);
            $lines[] = Line::fromSpans(Span::fromString(' '), Span::styled('[ No ]', $this->prompt->confirmed ? Style::default() : $selected), Span::fromString('  '), Span::styled('[ Yes ]', $this->prompt->confirmed ? $selected : Style::default()));
            $lines[] = Line::fromString('');

            foreach ($hints as $hint) {
                $lines[] = Line::fromSpan(Span::styled(' '.$hint, Style::default()->fg(AnsiColor::DarkGray)));
            }
        }

        $box = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->titles(Title::fromString(' Confirm action '))
            ->widget(ParagraphWidget::fromText(Text::fromLines(...$lines)));

        return BlockWidget::default()
            ->padding(Padding::fromScalars($left - $area->left(), max(0, $area->right() - $left - $width), $top - $area->top(), max(0, $area->bottom() - $top - $height)))
            ->widget($box);
    }
}
