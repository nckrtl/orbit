<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\Prompt;
use Laravel\Prompts\Themes\Default\TextPromptRenderer;

/** The stock TextPromptRenderer, with its box as wide as the panel that draws it. */
final class PanelTextPromptRenderer extends TextPromptRenderer
{
    /** The width the current panel offers; set before a frame is rendered. */
    public static int $width = 60;

    public function __construct(Prompt $prompt)
    {
        parent::__construct($prompt);
        $this->minWidth = max(20, self::$width);
    }
}
