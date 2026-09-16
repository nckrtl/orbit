<?php

declare(strict_types=1);

/** Normalize layout spacing only; retain every label, value and their order. */
function instance_source_text(string $output): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(
        ['│', '├', '└', '┌', '┬', '┴', '┤', '┼', '─', '╭', '╮', '╰', '╯'],
        ' ',
        $output,
    )));
}
