<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Themes\Default\DataTableRenderer;

/**
 * The stock Laravel Prompts data list, except that a chosen row leaves nothing behind:
 * the result the caller renders takes the list's place in the scrollback.
 */
final class EphemeralDataTableRenderer extends DataTableRenderer
{
    public function __invoke(DataTablePrompt $prompt): string
    {
        if ($prompt->state === 'submit') {
            return '';
        }

        return parent::__invoke($prompt);
    }
}
