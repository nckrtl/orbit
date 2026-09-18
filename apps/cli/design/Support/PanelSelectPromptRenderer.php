<?php

declare(strict_types=1);

namespace Design\Support;

use Laravel\Prompts\Prompt;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;

/** The stock SelectPromptRenderer, with its box as wide as the panel that draws it. */
final class PanelSelectPromptRenderer extends SelectPromptRenderer
{
    /** The width the current panel offers; set before a frame is rendered. */
    public static int $width = 60;

    public function __construct(Prompt $prompt)
    {
        parent::__construct($prompt);
        $this->minWidth = max(20, self::$width);
    }
}
