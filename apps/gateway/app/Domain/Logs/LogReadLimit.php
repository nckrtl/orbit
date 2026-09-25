<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/**
 * The byte limit of a one-shot log read over SSH, and the rule that keeps its lines whole.
 *
 * A follow that polls asks for up to 1,000 lines to find the line it printed last, and a line can
 * be long. 4 MiB holds 1,000 lines of about 4 KiB each. When the output passes the limit, the SSH
 * layer keeps its end, so the first line can start in the middle; that part is dropped.
 */
final class LogReadLimit
{
    public const int Bytes = 4 * 1024 * 1024;

    /** The output with a first line that the limit cut in the middle removed. */
    public static function wholeLines(string $output): string
    {
        if (strlen($output) < self::Bytes) {
            return $output;
        }

        $newline = strpos($output, "\n");

        return $newline === false ? '' : substr($output, $newline + 1);
    }
}
