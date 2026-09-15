<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyIdentity;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class ReadYarnClassicDependencyGraphAction
{
    public function execute(string $manifestContents, string $lockContents): DependencyGraph
    {
        try {
            $manifest = $this->decode($manifestContents);

            if (property_exists($manifest, 'workspaces')) {
                throw new DependencyParseException('dependencies.unsupported_layout');
            }

            [$records, $selectors] = $this->parseLock($lockContents);

            return $this->read($manifest, $records, $selectors);
        } catch (JsonException|InvalidArgumentException) {
            throw new DependencyParseException('dependencies.invalid_yarn_classic_input');
        }
    }

    private function invalid(): never
    {
        throw new DependencyParseException('dependencies.invalid_yarn_classic_input');
    }

    /** @return array{array<string, array{list<string>, stdClass}>, array<string, string>} */
    private function parseLock(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $records = [];
        $selectors = [];
        $current = null;
        $section = null;
        $hasHeader = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $contents)) as $line) {
            if (preg_match('/^\s*# yarn lockfile v(\S+)/', $line, $version) === 1) {
                if ($version[1] !== '1') {
                    throw new DependencyParseException('dependencies.unsupported_format');
                }

                $hasHeader = true;
            }

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^(?:__metadata|"__metadata")\s*:/', $line) === 1) {
                throw new DependencyParseException('dependencies.unsupported_format');
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $tokens = $this->tokens(substr($line, $indent));

            if ($indent === 0) {
                $group = [];

                while (count($tokens) >= 2) {
                    [$type, $selector] = array_shift($tokens);
                    [$separator] = array_shift($tokens);

                    if ($type !== 'value' || ! is_string($selector) || ! in_array($separator, [',', ':'], true)
                        || ($separator === ':' && $tokens !== []) || ($separator === ',' && $tokens === [])) {
                        $this->invalid();
                    }

                    $this->selector($selector);
                    $group[] = $selector;
                }

                if ($tokens !== [] || $group === [] || $separator !== ':') {
                    $this->invalid();
                }

                sort($group, SORT_STRING);
                $current = 'yarn-classic:sha256:'.hash('sha256', json_encode($group, JSON_THROW_ON_ERROR));

                foreach ($group as $selector) {
                    if (isset($selectors[$selector])) {
                        $this->invalid();
                    }

                    $selectors[$selector] = $current;
                }

                $records[$current] = [$group, new stdClass];
                $section = null;

                continue;
            }

            if ($current === null || ! in_array($indent, [2, 4], true) || count($tokens) !== 2
                || $tokens[0][0] !== 'value' || ! is_string($tokens[0][1])) {
                $this->invalid();
            }

            $record = $records[$current][1];
            $key = $tokens[0][1];
            [$type, $value] = $tokens[1];

            if ($indent === 2) {
                $section = null;
            } elseif ($section === null) {
                $this->invalid();
            } else {
                $record = $record->{$section};
            }

            if (property_exists($record, $key)) {
                $this->invalid();
            }

            if ($type === ':' && $indent === 2) {
                $record->{$key} = new stdClass;
                $section = $key;
            } elseif ($type === 'value') {
                $record->{$key} = $value;
            } else {
                $this->invalid();
            }
        }

        if ($records === [] && ! $hasHeader) {
            $this->invalid();
        }

        ksort($records, SORT_STRING);

        return [$records, $selectors];
    }

    /** @return list<array{string, mixed}> */
    private function tokens(string $line): array
    {
        $tokens = [];
        $offset = 0;

        while ($offset < strlen($line)) {
            if ($line[$offset] === ' ') {
                $offset++;

                continue;
            }

            if ($line[$offset] === '#') {
                break;
            }

            if (in_array($line[$offset], [':', ','], true)) {
                $tokens[] = [$line[$offset], null];
                $offset++;

                continue;
            }

            if (preg_match('/\G"(?:[^"\\\\]|\\\\.)*"/', $line, $match, offset: $offset) === 1) {
                $value = json_decode($match[0], flags: JSON_THROW_ON_ERROR);
            } elseif (preg_match('/\G(?:true|false|[0-9]+|[a-zA-Z\/.\-][^\s:",\[\]\\\\]*)/', $line, $match, offset: $offset) === 1) {
                $value = match ($match[0]) {
                    'true' => true,
                    'false' => false,
                    default => ctype_digit($match[0]) ? (int) $match[0] : $match[0],
                };
            } else {
                $this->invalid();
            }

            $tokens[] = ['value', $value];
            $offset += strlen($match[0]);

            if ($offset < strlen($line) && ! in_array($line[$offset], [' ', ':', ',', '#'], true)) {
                $this->invalid();
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, array{list<string>, stdClass}>  $records
     * @param  array<string, string>  $selectors
     */
    private function read(stdClass $manifest, array $records, array $selectors): DependencyGraph
    {
        $requirements = $this->requirements(null, $manifest, $selectors, true);
        $identities = [];

        foreach ($records as $id => [$group, $record]) {
            foreach (array_keys(get_object_vars($record)) as $field) {
                if (! in_array($field, ['name', 'version', 'uid', 'resolved', 'integrity', 'registry', 'dependencies', 'optionalDependencies', 'permissions', 'prebuiltVariants'], true)) {
                    $this->invalid();
                }
            }

            $names = [];

            foreach ($group as $selector) {
                [$name, $constraint] = $this->selector($selector);
                $names[] = $this->targetName($name, $constraint);
            }

            if (count(array_unique($names)) !== 1) {
                $this->invalid();
            }

            $name = property_exists($record, 'name') ? $this->packageName($record->name) : $names[0];

            foreach ($group as $selector) {
                [$declared, $constraint] = $this->selector($selector);

                if (! $this->isExotic($constraint) && $name !== $this->targetName($declared, $constraint)) {
                    $this->invalid();
                }
            }

            $identities[$id] = $name;
            $this->version($record->version ?? null);
            $this->integrity($record);

            if (property_exists($record, 'uid')) {
                $this->version($record->uid);
            }

            if (property_exists($record, 'registry') && $record->registry !== 'npm') {
                throw new DependencyParseException('dependencies.unsupported_format');
            }

            if (property_exists($record, 'resolved')) {
                $this->constraint($record->resolved);
            }

            foreach ($this->map($record, 'permissions') as $permission) {
                if (! is_bool($permission)) {
                    $this->invalid();
                }
            }

            foreach ($this->map($record, 'prebuiltVariants') as $variant) {
                if (! is_string($variant)) {
                    $this->invalid();
                }
            }

            array_push($requirements, ...$this->requirements($id, $record, $selectors, false));
        }

        $regular = $this->reachable($requirements, DependencyScope::Regular);
        $development = $this->reachable($requirements, DependencyScope::Development);
        $resolutions = [];

        foreach ($records as $id => [, $record]) {
            if (! isset($regular[$id]) && ! isset($development[$id])) {
                $this->invalid();
            }

            $reference = null;

            if (is_string($record->resolved ?? null) && preg_match('/#([a-f0-9]{7,64})$/iD', $record->resolved, $match) === 1) {
                $reference = $match[1];
            }

            $resolutions[] = new DependencyResolution(
                id: $id,
                package: new DependencyIdentity(DependencyEcosystem::Npm, $identities[$id]),
                version: $record->version,
                regular: isset($regular[$id]),
                development: isset($development[$id]),
                sourceReference: $reference,
                integrity: $this->integrity($record),
            );
        }

        return new DependencyGraph(DependencyEcosystem::Npm, $resolutions, $requirements);
    }

    /**
     * @param  array<string, string>  $selectors
     * @return list<DependencyRequirement>
     */
    private function requirements(?string $from, stdClass $record, array $selectors, bool $root): array
    {
        $optional = $this->links($record, 'optionalDependencies');
        $regular = $this->links($record, 'dependencies');
        $selected = [];
        $sections = [
            'dependencies' => array_diff_key($regular, $optional),
            'optionalDependencies' => $optional,
        ];

        if ($root) {
            $sections['devDependencies'] = $this->links($record, 'devDependencies');

            // Classic retains the first section but prefers the first nontrivial range.
            foreach ([$optional, $regular, $sections['devDependencies']] as $links) {
                foreach ($links as $name => $constraint) {
                    if (! array_key_exists($name, $selected)
                        || (in_array($selected[$name], ['', '*'], true) && ! in_array($constraint, ['', '*'], true))) {
                        $selected[$name] = $constraint;
                    }
                }
            }
        }

        $requirements = [];

        foreach ($sections as $field => $links) {
            foreach ($links as $name => $constraint) {
                $reference = $root ? $selected[$name] : $constraint;
                $target = $root && isset($selectors[$name])
                    ? $selectors[$name]
                    : ($selectors[$name.'@'.$reference] ?? null);

                if ($target === null && $field !== 'optionalDependencies' && ! ($root && array_key_exists($name, $optional))) {
                    $this->invalid();
                }

                $requirements[] = new DependencyRequirement($from, $target, (string) $name, $this->safeConstraint($constraint),
                    DependencyRequirementKind::Dependency,
                    $field === 'devDependencies' ? DependencyScope::Development : DependencyScope::Regular,
                    $field === 'optionalDependencies');
            }
        }

        if ($root) {
            $peers = $this->peers($record);

            foreach ($this->links($record, 'peerDependencies') as $name => $constraint) {
                $requirements[] = new DependencyRequirement(null, null, (string) $name, $this->safeConstraint($constraint),
                    DependencyRequirementKind::Peer, DependencyScope::Regular, $peers[$name]);
            }
        }

        return $requirements;
    }

    /** @return array{string, string} */
    private function selector(string $selector): array
    {
        if (preg_match('{^((?:@[^/]+/)?[^@]+)@(.*)$}D', $selector, $match) !== 1) {
            return [$this->packageName($selector), 'latest'];
        }

        return [$this->packageName($match[1]), $this->constraint($match[2])];
    }

    private function isExotic(string $value): bool
    {
        return ! str_starts_with($value, 'npm:') && preg_match('{[:/@#]}', $value) === 1;
    }

    private function constraint(mixed $value): string
    {
        if (! is_string($value) || preg_match('/[\x00-\x1f\x7f\\\\]/', $value) === 1) {
            $this->invalid();
        }

        if (preg_match('{^(?:file:|link:|workspace:|portal:|patch:|catalog:|\.{1,2}/|/|~/)}i', $value) === 1) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        if ($this->isExotic($value)) {
            if (preg_match('/\s/', $value) === 1) {
                $this->invalid();
            }

            if (preg_match('{^(?:https?|git(?:\+https?|\+ssh)?|ssh)://}i', $value) === 1) {
                $url = parse_url($value);

                if ($url === false || empty($url['host'])) {
                    $this->invalid();
                }
            } elseif (preg_match('{^(?:(?:github|gitlab|bitbucket|gist):[^\s]+|git@[^:]+:[^\s]+|[a-z0-9_.-]+/[a-z0-9_.-]+(?:\#[^\s]+)?)$}iD', $value) !== 1) {
                $this->invalid();
            }
        } elseif (! str_starts_with($value, 'npm:') && preg_match('/[^a-z0-9 ._~^*|<>=+\-]/i', $value) === 1) {
            $this->invalid();
        }

        $this->targetName('unused', $value);

        return $value;
    }

    private function safeConstraint(string $value): string
    {
        return $this->isExotic($value) ? 'yarn-classic:sha256:'.hash('sha256', $value) : $value;
    }

    private function targetName(string $name, string $constraint): string
    {
        if (! str_starts_with($constraint, 'npm:')) {
            return $name;
        }

        if (preg_match('{^npm:((?:@[^/]+/)?[^@]+)(?:@([^:/@?\x00-\x1f\x7f\\\\]*))?$}D', $constraint, $match) !== 1) {
            $this->invalid();
        }

        return $this->packageName($match[1]);
    }

    /** @return array<array-key, mixed> */
    private function map(stdClass $record, string $field, bool $required = false): array
    {
        if (! property_exists($record, $field) && ! $required) {
            return [];
        }

        if (! ($record->{$field} ?? null) instanceof stdClass) {
            $this->invalid();
        }

        $values = get_object_vars($record->{$field});
        ksort($values, SORT_STRING);

        return $values;
    }

    /** @return array<array-key, string> */
    private function links(stdClass $record, string $field): array
    {
        $links = [];

        foreach ($this->map($record, $field) as $name => $constraint) {
            $links[$this->packageName((string) $name)] = $this->constraint($constraint);
        }

        return $links;
    }

    /** @return array<array-key, bool> */
    private function peers(stdClass $record): array
    {
        $peers = array_fill_keys(array_keys($this->links($record, 'peerDependencies')), false);

        foreach ($this->map($record, 'peerDependenciesMeta') as $name => $meta) {
            if (! array_key_exists($name, $peers) || ! $meta instanceof stdClass
                || (property_exists($meta, 'optional') && ! is_bool($meta->optional))) {
                $this->invalid();
            }

            $peers[$name] = $meta->optional ?? false;
        }

        return $peers;
    }

    private function decode(string $contents): stdClass
    {
        $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

        if (! $value instanceof stdClass) {
            $this->invalid();
        }

        // JSON decoding otherwise silently discards earlier declarations of a key.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]/s', $contents, $tokens, PREG_OFFSET_CAPTURE);
        $objects = [];

        foreach ($tokens[0] as [$token, $offset]) {
            if ($token === '{' || $token === '[') {
                $objects[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($objects);
            } elseif (preg_match('/\G\s*:/', $contents, offset: $offset + strlen($token)) === 1) {
                $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $index = count($objects) - 1;

                if (isset($objects[$index][$key])) {
                    $this->invalid();
                }

                $objects[$index][$key] = true;
            }
        }

        return $value;
    }

    private function packageName(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 214
            || preg_match('{^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$}iD', $value) !== 1) {
            $this->invalid();
        }

        return $value;
    }

    private function version(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || preg_match('/[\s:@?\x00-\x1f\x7f\\\\]/', $value) === 1) {
            $this->invalid();
        }

        return $value;
    }

    private function integrity(stdClass $record): ?string
    {
        if (! property_exists($record, 'integrity')) {
            return null;
        }

        if (! is_string($record->integrity)
            || preg_match('/^sha(?:1|256|384|512)-[A-Za-z0-9+\/]+=*(?: sha(?:1|256|384|512)-[A-Za-z0-9+\/]+=*)*$/D', $record->integrity) !== 1) {
            $this->invalid();
        }

        return $record->integrity;
    }

    /**
     * @param  list<DependencyRequirement>  $requirements
     * @return array<string, true>
     */
    private function reachable(array $requirements, DependencyScope $scope): array
    {
        $children = [];
        $pending = [];

        foreach ($requirements as $requirement) {
            if ($requirement->to === null) {
                continue;
            }

            if ($requirement->from === null) {
                if ($requirement->scope === $scope) {
                    $pending[] = $requirement->to;
                }
            } else {
                $children[$requirement->from][] = $requirement->to;
            }
        }

        $seen = [];

        while ($pending !== []) {
            $location = array_pop($pending);

            if (isset($seen[$location])) {
                continue;
            }

            $seen[$location] = true;
            array_push($pending, ...($children[$location] ?? []));
        }

        return $seen;
    }
}
