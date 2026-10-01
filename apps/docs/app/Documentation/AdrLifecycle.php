<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use RuntimeException;

final readonly class AdrLifecycle
{
    public const string RULE = 'orbit.adr_lifecycle';

    // The adoption set is fixed; the editable allowlist can only remove entries from it.
    private const array ADOPTION_NUMBERS = [
        '0051', '0052', '0053', '0054', '0058', '0059', '0076', '0097',
        '0103', '0104', '0109', '0110', '0112', '0113', '0114', '0116',
        '0117', '0119', '0121', '0122', '0124', '0125', '0131', '0132', '0133',
        '0134', '0140', '0145', '0150', '0160', '0163', '0164', '0165',
        '0166', '0167', '0168', '0169', '0170', '0171', '0172', '0173', '0174',
        '0175', '0177', '0178',
    ];

    public function __construct(private string $root) {}

    /** @return list<Finding> */
    public function findings(): array
    {
        $findings = [];
        $overview = $this->read('docs/decisions/overview.mdx');
        $navigation = json_decode($this->read('docs/docs.json'), true, flags: JSON_THROW_ON_ERROR);
        $redirects = [];
        $entries = is_array($navigation) ? ($navigation['redirects'] ?? []) : [];
        foreach (is_array($entries) ? $entries : [] as $redirect) {
            if (is_array($redirect) && is_string($redirect['source'] ?? null) && is_string($redirect['destination'] ?? null)) {
                $redirects[$redirect['source']] = $redirect['destination'];
            }
        }

        $retired = explode('## Retired decisions', $overview, 2)[1] ?? '';
        preg_match_all('/^\| (\d{4}) \| ([^|]+) \|/m', $retired, $rows, PREG_SET_ORDER);
        $retiredSlugs = require $this->root.'/apps/docs/config/adr-retired-slugs.php';
        if (! is_array($retiredSlugs)) {
            throw new RuntimeException('Retired ADR slugs must be configured as an array.');
        }
        $listed = [];
        $files = glob($this->root.'/docs/decisions/[0-9][0-9][0-9][0-9]-*.md') ?: [];
        $live = [];
        foreach ($files as $file) {
            $slug = basename($file, '.md');
            $live[$slug] = $file;
        }

        foreach ($rows as $row) {
            $number = $row[1];
            $listed[$number] = ($listed[$number] ?? 0) + 1;
        }
        $seen = [];
        foreach ($retiredSlugs as $number => $slugs) {
            if (! is_array($slugs)) {
                throw new RuntimeException('Retired ADR slugs must be lists keyed by number.');
            }
            foreach ($slugs as $slug) {
                if (! is_string($slug) || preg_match('/^'.preg_quote((string) $number, '/').'-[a-z0-9-]+$/', $slug) !== 1 || isset($seen[$slug])) {
                    $findings[] = $this->error('apps/docs/config/adr-retired-slugs.php', "Retired decision {$number} needs its exact source slug.");

                    continue;
                }
                $seen[$slug] = true;
                if (! isset($redirects['/decisions/'.$slug])) {
                    $findings[] = $this->error('docs/decisions/overview.mdx', "Retired decision {$number} has no redirect from /decisions/{$slug} in docs/docs.json.");
                }
                if (isset($live[$slug])) {
                    $findings[] = $this->error('docs/decisions/'.$slug.'.md', "Retired decision {$slug} still has a file.");
                }
            }
            if (count($slugs) > ($listed[$number] ?? 0)) {
                $findings[] = $this->error('apps/docs/config/adr-retired-slugs.php', "Retired slug {$number} has no row in the decisions overview.");
            }
        }
        foreach ($listed as $number => $count) {
            if (count($retiredSlugs[$number] ?? []) < $count) {
                $findings[] = $this->error('apps/docs/config/adr-retired-slugs.php', "Retired decision {$number} needs its exact source slug.");
            }
        }

        $allowlistPath = 'apps/docs/config/adr-legacy-allowlist.php';
        $allowed = $this->allowlist($this->root.'/'.$allowlistPath);
        foreach ($allowed as $number) {
            if (! in_array($number, self::ADOPTION_NUMBERS, true)) {
                $findings[] = $this->error($allowlistPath, "Legacy ADR allowlist cannot add {$number}.");
            }
        }
        // Check every reachable committed allowance, including merged branches. Log order is
        // not ancestry order: compare each historical set with today, never with its neighbor.
        // A later correction passes once the number is absent from today's allowance.
        $history = $this->git(['log', '--full-history', '--format=%H', 'HEAD', '--', $allowlistPath]);
        $restored = [];
        foreach (explode("\n", trim($history ?? '')) as $commit) {
            if ($commit === '') {
                continue;
            }
            $contents = $this->git(['show', $commit.':'.$allowlistPath]);
            if ($contents !== null) {
                array_push($restored, ...array_diff($allowed, $this->parseAllowlist($contents)));
            }
        }
        foreach (array_unique($restored) as $number) {
            $findings[] = $this->error($allowlistPath, "Legacy ADR allowlist cannot add {$number}.");
        }

        foreach ($allowed as $number) {
            $hasRecord = array_any(array_keys($live), fn ($slug) => str_starts_with($slug, $number.'-'));
            if (! $hasRecord) {
                $findings[] = $this->error($allowlistPath, "Legacy ADR {$number} is no longer live; remove it from the allowlist.");
            }
        }

        foreach ($live as $slug => $file) {
            $number = substr($slug, 0, 4);
            $path = 'docs/decisions/'.$slug.'.md';
            // Adopted numbers below 0180 stay on the shrinking allowlist, even when an
            // older slug of that number is retired (the 0114 Tasks and Incus records).
            // Every other retired number is closed. A gap that was never adopted and is
            // not in Retired decisions (0007 and 0020) follows the 0180+ rules.
            if ((int) $number < 180 && in_array($number, self::ADOPTION_NUMBERS, true)) {
                if (! in_array($number, $allowed, true)) {
                    $findings[] = $this->error($path, "Unretired legacy ADR {$number} is not in the allowlist.");
                }

                continue;
            }

            if (isset($listed[$number])) {
                $findings[] = $this->error($path, "Retired ADR number {$number} cannot be reused.");

                continue;
            }

            $record = DecisionRecordFile::fromContents('decisions/'.$slug.'.md', $this->read($path));
            if ($record === null) {
                continue;
            }
            $statusLine = null;
            foreach ($record->headings() as $heading) {
                if ($heading['level'] === 2 && $heading['text'] === 'Status') {
                    $statusLine = $heading['line'];
                    break;
                }
            }
            $status = $statusLine === null ? [] : array_map(trim(...), $record->sectionLines($statusLine));
            if (! in_array('In progress.', $status, true)) {
                $findings[] = $this->error($path, 'ADR 0180+ must say In progress. in its Status section.');
            }
            if (count(array_filter($status, static fn (string $line): bool => preg_match('/^Principle:\s*\S/', $line) === 1)) === 0) {
                $findings[] = $this->error($path, 'ADR 0180+ must include a Principle: line in its Status section.');
            }
        }

        return $findings;
    }

    /** @return list<string> */
    private function allowlist(string $file): array
    {
        return $this->parseAllowlist(file_get_contents($file) ?: '');
    }

    /** @return list<string> */
    private function parseAllowlist(string $contents): array
    {
        preg_match_all('/[\'\"](\d{4})[\'\"]/', $contents, $matches);

        return $matches[1];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root.'/'.$path);
        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        return $contents;
    }

    /** @param list<string> $args */
    private function git(array $args): ?string
    {
        $command = 'git -C '.escapeshellarg($this->root).' '.implode(' ', array_map(escapeshellarg(...), $args)).' 2>/dev/null';
        exec($command, $lines, $exit);

        return $exit === 0 ? implode("\n", $lines) : null;
    }

    private function error(string $path, string $message): Finding
    {
        return new Finding($path, null, FindingSeverity::Error, self::RULE, $message);
    }
}
