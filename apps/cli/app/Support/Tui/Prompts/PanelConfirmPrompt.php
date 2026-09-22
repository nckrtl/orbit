<?php

declare(strict_types=1);

namespace App\Support\Tui\Prompts;

use Laravel\Prompts\ConfirmPrompt;

/** A default-No ConfirmPrompt driven by the enclosing TUI, without terminal reads. */
final class PanelConfirmPrompt extends ConfirmPrompt
{
    use PanelPrompt;
}
