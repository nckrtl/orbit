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

    /**
     * Journal output read newest entry first and cut at `Bytes`, put back in time order. Reading
     * backwards lets the Node stop after `Bytes`, because `journalctl --lines` counts entries, and
     * one entry can have millions of lines. A line that starts with a space continues the entry
     * above it. The oldest entry may have lost lines to the cut, so a cut output drops it, and a
     * line without its newline is dropped too.
     */
    public static function journalInTimeOrder(string $reversed): string
    {
        $cut = strlen($reversed) >= self::Bytes;
        $lines = explode("\n", $reversed);
        // The part after the last newline is empty, or a line the cut ended early.
        array_pop($lines);

        $entries = [];

        foreach ($lines as $line) {
            if ($line !== '' && $line[0] === ' ' && $entries !== []) {
                $entries[count($entries) - 1][] = $line;
            } else {
                $entries[] = [$line];
            }
        }

        if ($cut) {
            array_pop($entries);
        }

        $ordered = array_merge(...array_reverse($entries));

        return $ordered === [] ? '' : implode("\n", $ordered)."\n";
    }
}
