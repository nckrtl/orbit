<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

/**
 * Loose node-semver range matching, as npm uses it to validate a locked version against a spec.
 *
 * A spec that is neither a version nor a range is an npm dist-tag; parse() returns null for it.
 */
final readonly class NpmVersionRange
{
    private const string NUMBER = '(?:0|[1-9]\d*|\d+)';

    private const string PART = '(?:\d+|x|X|\*)';

    private const string PRERELEASE = '(?:\d+|\d*[a-zA-Z-][a-zA-Z0-9-]*)(?:\.(?:\d+|\d*[a-zA-Z-][a-zA-Z0-9-]*))*';

    private const string BUILD = '[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*';

    /** @param list<list<array{string, array{int, int, int, list<string>}}|null>> $sets */
    private function __construct(private array $sets) {}

    public static function parse(string $spec): ?self
    {
        $sets = [];

        foreach (preg_split('/\s*\|\|\s*/', trim($spec)) ?: [] as $raw) {
            $set = self::parseSet(trim($raw));

            if ($set !== []) {
                $sets[] = $set;
            }
        }

        return $sets === [] ? null : new self($sets);
    }

    public function satisfies(string $version): bool
    {
        $parsed = self::version($version);

        if ($parsed === null) {
            return false;
        }

        return array_any($this->sets, fn ($set) => self::testSet($set, $parsed));
    }

    /** @return array{int, int, int, list<string>}|null */
    public static function version(string $value): ?array
    {
        $pattern = '/^\s*[v=\s]*('.self::NUMBER.')\.('.self::NUMBER.')\.('.self::NUMBER.')(?:-?('.self::PRERELEASE.'))?(?:\+'.self::BUILD.')?\s*$/D';

        if (preg_match($pattern, $value, $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2], (int) $match[3], isset($match[4]) ? explode('.', $match[4]) : []];
    }

    /** @return list<array{string, array{int, int, int, list<string>}}|null> */
    private static function parseSet(string $range): array
    {
        $plain = '[v=\s]*'.self::PART.'(?:\.'.self::PART.'(?:\.'.self::PART.'(?:-?'.self::PRERELEASE.')?(?:\+'.self::BUILD.')?)?)?';

        if (preg_match('/^\s*('.$plain.')\s+-\s+('.$plain.')\s*$/D', $range, $hyphen) === 1) {
            $range = self::hyphen($hyphen[1], $hyphen[2]);
        }

        $range = preg_replace('/(\s*)((?:<|>)?=?)\s*([v=\s]*\d+\.\d+\.\d+|'.$plain.')/', '$1$2$3', $range) ?? $range;
        $range = preg_replace('/(\s*)~>?\s+/', '$1~', $range) ?? $range;
        $range = preg_replace('/(\s*)\^\s+/', '$1^', $range) ?? $range;
        $comparators = [];

        foreach (preg_split('/\s+/', trim($range)) ?: [] as $token) {
            foreach (preg_split('/\s+/', trim(self::desugar($token))) ?: [] as $comparator) {
                $parsed = self::comparator($comparator);

                // Loose parsing drops tokens that are not comparators.
                if ($parsed !== false) {
                    $comparators[] = $parsed;
                }
            }
        }

        if (in_array(null, $comparators, true) && count(array_unique(array_map(serialize(...), $comparators))) > 1) {
            $comparators = array_values(array_filter($comparators, static fn (?array $comparator): bool => $comparator !== null));
        }

        return $comparators;
    }

    /** @return array{string, array{int, int, int, list<string>}}|null|false */
    private static function comparator(string $value): array|false|null
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^((?:<|>)?=?)\s*(.+)$/D', $value, $match) !== 1) {
            return false;
        }

        $version = self::version($match[2]);

        return $version === null ? false : [$match[1] === '=' ? '' : $match[1], $version];
    }

    private static function desugar(string $token): string
    {
        $x = '[v=\s]*('.self::PART.')(?:\.('.self::PART.')(?:\.('.self::PART.')(?:-?('.self::PRERELEASE.'))?(?:\+'.self::BUILD.')?)?)?';

        if (preg_match('/^\^'.$x.'$/D', $token, $m) === 1) {
            $token = self::caret($m[1], $m[2] ?? '', $m[3] ?? '', $m[4] ?? '');
        } elseif (preg_match('/^~>?'.$x.'$/D', $token, $m) === 1) {
            $token = self::tilde($m[1], $m[2] ?? '', $m[3] ?? '', $m[4] ?? '');
        }

        $parts = [];

        foreach (preg_split('/\s+/', trim($token)) ?: [] as $comparator) {
            if (preg_match('/^((?:<|>)?=?)\s*'.$x.'$/D', $comparator, $m) === 1) {
                $comparator = self::xrange($m[1], $m[2], $m[3] ?? '', $m[4] ?? '', $comparator);
            }

            // A star comparator matches anything.
            $parts[] = preg_replace('/^(?:<|>)?=?\s*\*$/', '', trim($comparator)) ?? $comparator;
        }

        return implode(' ', $parts);
    }

    private static function isX(string $part): bool
    {
        return $part === '' || in_array(strtolower($part), ['x', '*'], true);
    }

    private static function caret(string $major, string $minor, string $patch, string $prerelease): string
    {
        if (self::isX($major)) {
            return '';
        }

        if (self::isX($minor)) {
            return ">={$major}.0.0 <".((int) $major + 1).'.0.0-0';
        }

        if (self::isX($patch)) {
            return $major === '0'
                ? ">={$major}.{$minor}.0 <{$major}.".((int) $minor + 1).'.0-0'
                : ">={$major}.{$minor}.0 <".((int) $major + 1).'.0.0-0';
        }

        $from = ">={$major}.{$minor}.{$patch}".($prerelease === '' ? '' : '-'.$prerelease);

        if ($major === '0') {
            return $minor === '0'
                ? "{$from} <{$major}.{$minor}.".((int) $patch + 1).'-0'
                : "{$from} <{$major}.".((int) $minor + 1).'.0-0';
        }

        return "{$from} <".((int) $major + 1).'.0.0-0';
    }

    private static function tilde(string $major, string $minor, string $patch, string $prerelease): string
    {
        if (self::isX($major)) {
            return '';
        }

        if (self::isX($minor)) {
            return ">={$major}.0.0 <".((int) $major + 1).'.0.0-0';
        }

        if (self::isX($patch)) {
            return ">={$major}.{$minor}.0 <{$major}.".((int) $minor + 1).'.0-0';
        }

        return ">={$major}.{$minor}.{$patch}".($prerelease === '' ? '' : '-'.$prerelease)." <{$major}.".((int) $minor + 1).'.0-0';
    }

    private static function xrange(string $operator, string $major, string $minor, string $patch, string $original): string
    {
        $xMajor = self::isX($major);
        $xMinor = $xMajor || self::isX($minor);
        $anyX = $xMinor || self::isX($patch);

        if ($operator === '=' && $anyX) {
            $operator = '';
        }

        if ($xMajor) {
            return $operator === '>' || $operator === '<' ? '<0.0.0-0' : '*';
        }

        if ($operator !== '' && $anyX) {
            $major = (int) $major;
            $minor = $xMinor ? 0 : (int) $minor;
            $patch = 0;
            $suffix = '';

            if ($operator === '>') {
                $operator = '>=';
                $xMinor ? [$major, $minor] = [$major + 1, 0] : $minor++;
            } elseif ($operator === '<=') {
                $operator = '<';
                $xMinor ? $major++ : $minor++;
            }

            if ($operator === '<') {
                $suffix = '-0';
            }

            return "{$operator}{$major}.{$minor}.{$patch}{$suffix}";
        }

        if ($xMinor) {
            return ">={$major}.0.0 <".((int) $major + 1).'.0.0-0';
        }

        if ($anyX) {
            return ">={$major}.{$minor}.0 <{$major}.".((int) $minor + 1).'.0-0';
        }

        return $original;
    }

    private static function hyphen(string $from, string $to): string
    {
        $part = '[v=\s]*('.self::PART.')(?:\.('.self::PART.')(?:\.('.self::PART.')(?:-?('.self::PRERELEASE.'))?)?)?';
        preg_match('/^'.$part.'/', $from, $f);
        preg_match('/^'.$part.'/', $to, $t);
        [$fMajor, $fMinor, $fPatch, $fPre] = [$f[1] ?? '', $f[2] ?? '', $f[3] ?? '', $f[4] ?? ''];
        [$tMajor, $tMinor, $tPatch, $tPre] = [$t[1] ?? '', $t[2] ?? '', $t[3] ?? '', $t[4] ?? ''];

        $lower = match (true) {
            self::isX($fMajor) => '',
            self::isX($fMinor) => ">={$fMajor}.0.0",
            self::isX($fPatch) => ">={$fMajor}.{$fMinor}.0",
            default => ">={$fMajor}.{$fMinor}.{$fPatch}".($fPre === '' ? '' : '-'.$fPre),
        };
        $upper = match (true) {
            self::isX($tMajor) => '',
            self::isX($tMinor) => '<'.((int) $tMajor + 1).'.0.0-0',
            self::isX($tPatch) => "<{$tMajor}.".((int) $tMinor + 1).'.0-0',
            default => "<={$tMajor}.{$tMinor}.{$tPatch}".($tPre === '' ? '' : '-'.$tPre),
        };

        return trim("{$lower} {$upper}");
    }

    /**
     * @param  list<array{string, array{int, int, int, list<string>}}|null>  $set
     * @param  array{int, int, int, list<string>}  $version
     */
    private static function testSet(array $set, array $version): bool
    {
        foreach ($set as $comparator) {
            if ($comparator !== null && ! self::test($comparator, $version)) {
                return false;
            }
        }

        if ($version[3] === []) {
            return true;
        }

        return array_any($set, fn ($comparator) => $comparator !== null && $comparator[1][3] !== [] && array_slice($comparator[1], 0, 3) === array_slice($version, 0, 3));
    }

    /**
     * @param  array{string, array{int, int, int, list<string>}}  $comparator
     * @param  array{int, int, int, list<string>}  $version
     */
    private static function test(array $comparator, array $version): bool
    {
        $order = self::compare($version, $comparator[1]);

        return match ($comparator[0]) {
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
            default => $order === 0,
        };
    }

    /**
     * @param  array{int, int, int, list<string>}  $left
     * @param  array{int, int, int, list<string>}  $right
     */
    private static function compare(array $left, array $right): int
    {
        $main = [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]];

        if ($main !== 0) {
            return $main;
        }

        [$a, $b] = [$left[3], $right[3]];

        // A release sorts after its prereleases.
        if ($a === [] || $b === []) {
            return ($a === []) <=> ($b === []);
        }

        for ($index = 0; ; $index++) {
            $x = $a[$index] ?? null;
            $y = $b[$index] ?? null;

            if ($x === null || $y === null) {
                return $x === $y ? 0 : ($x === null ? -1 : 1);
            }

            if ($x === $y) {
                continue;
            }

            $xNumeric = ctype_digit($x);
            $yNumeric = ctype_digit($y);

            if ($xNumeric && $yNumeric) {
                return (int) $x <=> (int) $y;
            }

            if ($xNumeric !== $yNumeric) {
                return $xNumeric ? -1 : 1;
            }

            return strcmp($x, $y) <=> 0;
        }
    }
}
