<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * Compares two Caddyfiles site by site. A site is a top-level block keyed by its address line;
 * comments, blank lines, and indentation do not count. The global options block is keyed `{ }`.
 */
final readonly class CaddyfileSiteDiff
{
    public const string GlobalOptions = '{ }';

    /**
     * @return list<array{address: string, status: 'same'|'changed'|'build only'|'live only', removed: list<string>, added: list<string>}>
     */
    public static function compare(string $live, string $build): array
    {
        $liveSites = self::sites($live);
        $buildSites = self::sites($build);
        $rows = [];

        foreach ($buildSites as $address => $lines) {
            if (! array_key_exists($address, $liveSites)) {
                $rows[] = ['address' => $address, 'status' => 'build only', 'removed' => [], 'added' => []];

                continue;
            }

            $removed = self::without($liveSites[$address], $lines);
            $added = self::without($lines, $liveSites[$address]);
            $rows[] = [
                'address' => $address,
                'status' => $removed === [] && $added === [] ? 'same' : 'changed',
                'removed' => $removed,
                'added' => $added,
            ];
        }

        foreach (array_keys($liveSites) as $address) {
            if (! array_key_exists($address, $buildSites)) {
                $rows[] = ['address' => $address, 'status' => 'live only', 'removed' => [], 'added' => []];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, list<string>> Normalized lines of each top-level block, keyed by address.
     */
    public static function sites(string $caddyfile): array
    {
        $sites = [];
        $depth = 0;
        $address = null;
        $lines = [];

        foreach (preg_split('/\R/', $caddyfile) ?: [] as $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($depth === 0) {
                if (! str_ends_with($line, '{')) {
                    $sites[$line] = [];

                    continue;
                }

                $key = trim(substr($line, 0, -1));
                $address = $key === '' ? self::GlobalOptions : $key;
                $suffix = 2;

                while (array_key_exists($address, $sites)) {
                    $address = ($key === '' ? self::GlobalOptions : $key)." #{$suffix}";
                    $suffix++;
                }

                $lines = [];
                $depth = 1;

                continue;
            }

            if (str_starts_with($line, '}')) {
                $depth--;
            }

            if (str_ends_with($line, '{')) {
                $depth++;
            }

            if ($depth === 0) {
                $sites[(string) $address] = $lines;
                $address = null;

                continue;
            }

            $lines[] = $line;
        }

        return $sites;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $other
     * @return list<string> The lines of `$lines` that `$other` lacks, counting repeats.
     */
    private static function without(array $lines, array $other): array
    {
        $counts = array_count_values($other);
        $missing = [];

        foreach ($lines as $line) {
            if (($counts[$line] ?? 0) > 0) {
                $counts[$line]--;

                continue;
            }

            $missing[] = $line;
        }

        return $missing;
    }
}
