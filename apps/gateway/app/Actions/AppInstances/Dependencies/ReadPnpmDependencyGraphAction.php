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
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ReadPnpmDependencyGraphAction
{
    public function execute(string $manifestContents, string $lockContents): DependencyGraph
    {
        try {
            $manifest = $this->decode($manifestContents);
            $lock = Yaml::parse($lockContents, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);

            if (! $lock instanceof stdClass) {
                $this->invalid();
            }

            return $this->read($manifest, $lock);
        } catch (JsonException|InvalidArgumentException|ParseException) {
            throw new DependencyParseException('dependencies.invalid_pnpm_input');
        }
    }

    private function stale(): never
    {
        throw new DependencyParseException('dependencies.stale_pnpm_lockfile');
    }

    private function invalid(): never
    {
        throw new DependencyParseException('dependencies.invalid_pnpm_input');
    }

    private function read(stdClass $manifest, stdClass $lock): DependencyGraph
    {
        foreach ([$manifest, $lock] as $record) {
            if (property_exists($record, 'workspaces') || property_exists($record, 'catalogs') || property_exists($record, 'catalog')) {
                throw new DependencyParseException('dependencies.unsupported_layout');
            }
        }

        if (! in_array($lock->lockfileVersion ?? null, ['9.0', 9.0], true)) {
            throw new DependencyParseException('dependencies.unsupported_format');
        }

        $importers = $this->map($lock, 'importers', true);

        if (array_keys($importers) !== ['.']) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        if (! $importers['.'] instanceof stdClass) {
            $this->invalid();
        }

        $packages = $this->map($lock, 'packages');
        $snapshots = $this->map($lock, 'snapshots');
        $records = [];
        $usedPackages = [];

        foreach ($packages as $key => $metadata) {
            if (! is_string($key) || ! $metadata instanceof stdClass) {
                $this->invalid();
            }

            [$base] = $this->locator($key);

            if ($base !== $key) {
                $this->invalid();
            }

            $this->metadata($metadata);
        }

        foreach ($snapshots as $id => $snapshot) {
            if (! is_string($id) || ! $snapshot instanceof stdClass) {
                $this->invalid();
            }

            [$base, $name, $keyVersion] = $this->locator($id);
            $metadata = $packages[$base] ?? null;

            if (! $metadata instanceof stdClass) {
                $this->invalid();
            }

            if (property_exists($metadata, 'name') && $this->packageName($metadata->name) !== $name) {
                $this->invalid();
            }

            $version = $this->version(property_exists($metadata, 'version') ? $metadata->version : $keyVersion);

            if ($keyVersion !== null && $version !== $keyVersion) {
                $this->invalid();
            }

            $records[$id] = [$name, $version, $metadata, $snapshot];
            $usedPackages[$base] = true;
        }

        if (array_diff_key($packages, $usedPackages) !== []) {
            $this->invalid();
        }

        $requirements = $this->rootRequirements($manifest, $importers['.'], $snapshots);

        foreach ($records as $id => [, , $metadata, $snapshot]) {
            array_push($requirements, ...$this->snapshotRequirements($id, $metadata, $snapshot, $snapshots));
        }

        $regular = $this->reachable($requirements, DependencyScope::Regular);
        $development = $this->reachable($requirements, DependencyScope::Development);
        $resolutions = [];

        foreach ($records as $id => [$name, $version, $metadata]) {
            if (! isset($regular[$id]) && ! isset($development[$id])) {
                $this->invalid();
            }

            $resolution = $metadata->resolution ?? new stdClass;
            $resolutions[] = new DependencyResolution(
                id: $this->safeReference($id),
                package: new DependencyIdentity(DependencyEcosystem::Npm, $name),
                version: $version,
                regular: isset($regular[$id]),
                development: isset($development[$id]),
                integrity: $this->integrity($resolution),
            );
        }

        $safeRequirements = array_map(fn (DependencyRequirement $requirement): DependencyRequirement => new DependencyRequirement(
            from: $requirement->from === null ? null : $this->safeReference($requirement->from),
            to: $requirement->to === null ? null : $this->safeReference($requirement->to),
            name: $requirement->name,
            constraint: $this->safeReference($requirement->constraint),
            kind: $requirement->kind,
            scope: $requirement->scope,
            optional: $requirement->optional,
        ), $requirements);

        return new DependencyGraph(DependencyEcosystem::Npm, $resolutions, $safeRequirements);
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
            if (! $meta instanceof stdClass || (property_exists($meta, 'optional') && ! is_bool($meta->optional))) {
                $this->invalid();
            }

            // pnpm records no peer for metadata without a declaration; the entry must still be well formed.
            if (array_key_exists($name, $peers)) {
                $peers[$name] = $meta->optional ?? false;
            }
        }

        return $peers;
    }

    private function metadata(stdClass $record): void
    {
        $this->peers($record);
        $resolution = $record->resolution ?? new stdClass;

        if (! $resolution instanceof stdClass || (property_exists($record, 'resolution') && $record->resolution === null)) {
            $this->invalid();
        }

        if (property_exists($resolution, 'directory') || ($resolution->type ?? null) === 'directory') {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        if (property_exists($resolution, 'tarball')) {
            if (! is_string($resolution->tarball)) {
                $this->invalid();
            }

            $this->rejectLocal($resolution->tarball);
        }

        $this->integrity($resolution);
    }

    /** @return array{string, string, ?string} */
    private function locator(string $id): array
    {
        $this->rejectLocal($id);

        if (preg_match('{^((?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*)@([^()]+)(.*)$}iD', $id, $match) !== 1) {
            $this->invalid();
        }

        $name = $this->packageName($match[1]);
        $this->rejectLocal($match[2]);
        $reference = $match[2];
        $version = $this->isRemote($reference) ? null : $this->version($reference);

        if ($version === null) {
            $this->validateRemote($reference);
        }
        $suffix = $match[3];
        $depth = 0;

        $suffixPattern = str_contains($suffix, '://')
            ? '{[^a-z0-9@/.:?%&#~_+=()\-]}i'
            : '{[^a-z0-9@/._+=()\-]}i';

        if (preg_match($suffixPattern, $suffix) === 1) {
            $this->invalid();
        }

        foreach (str_split($suffix) as $index => $char) {
            if ($char === '(') {
                if (($suffix[$index + 1] ?? ')') === ')') {
                    $this->invalid();
                }

                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0) {
                $this->invalid();
            }

            if ($depth < 0) {
                $this->invalid();
            }
        }

        if ($depth !== 0) {
            $this->invalid();
        }

        return [$name.'@'.$reference, $name, $version];
    }

    /** @param array<array-key, mixed> $snapshots */
    private function target(string $name, mixed $reference, array $snapshots): string
    {
        if (! is_string($reference)) {
            $this->invalid();
        }

        $this->rejectLocal($reference);
        $id = array_key_exists($reference, $snapshots) ? $reference : $name.'@'.$reference;
        $this->locator($id);

        if (! array_key_exists($id, $snapshots)) {
            $this->invalid();
        }

        return $id;
    }

    /**
     * @param  array<array-key, mixed>  $snapshots
     * @return list<DependencyRequirement>
     */
    private function rootRequirements(stdClass $manifest, stdClass $importer, array $snapshots): array
    {
        $peerOptional = $this->peers($manifest);
        $peers = $this->links($manifest, 'peerDependencies');
        $optional = $this->links($manifest, 'optionalDependencies');
        $sections = [
            'dependencies' => array_diff_key($this->links($manifest, 'dependencies'), $optional),
            'optionalDependencies' => $optional,
            'devDependencies' => $this->links($manifest, 'devDependencies'),
        ];
        $expectedSections = $sections;
        $expectedSections['devDependencies'] = array_diff_key($sections['devDependencies'], $sections['dependencies'], $optional);
        $requirements = [];
        $targets = [];

        foreach ($expectedSections as $field => $declared) {
            $locked = $this->map($importer, $field);

            foreach ($locked as $name => $entry) {
                $name = $this->packageName((string) $name);

                if (! $entry instanceof stdClass) {
                    $this->invalid();
                }

                $specifier = $this->constraint($entry->specifier ?? null);
                $expected = $declared[$name] ?? ($field === 'dependencies' ? ($peers[$name] ?? null) : null);

                // pnpm install rewrites the importer from package.json, so a specifier difference is a stale lock.
                if ($expected !== $specifier) {
                    $this->stale();
                }

                $id = $this->target($name, $entry->version ?? null, $snapshots);
                [, $identity] = $this->locator($id);

                if (! $this->isRemote($specifier) && $identity !== $this->targetName($name, $specifier)) {
                    $this->invalid();
                }

                if (isset($targets[$name]) && $targets[$name] !== $id) {
                    $this->invalid();
                }

                $targets[$name] = $id;

            }

            if (array_diff_key($declared, $locked) !== []) {
                $this->stale();
            }
        }

        foreach ($sections as $field => $declared) {
            foreach ($declared as $name => $constraint) {
                $requirements[] = new DependencyRequirement(null, $targets[$name], (string) $name, $constraint,
                    DependencyRequirementKind::Dependency,
                    $field === 'devDependencies' ? DependencyScope::Development : DependencyScope::Regular,
                    $field === 'optionalDependencies');
            }
        }

        foreach ($peers as $name => $constraint) {
            $requirements[] = new DependencyRequirement(null, $targets[$name] ?? null, (string) $name, $constraint,
                DependencyRequirementKind::Peer, DependencyScope::Regular, $peerOptional[$name]);
        }

        return $requirements;
    }

    /**
     * @param  array<array-key, mixed>  $snapshots
     * @return list<DependencyRequirement>
     */
    private function snapshotRequirements(string $id, stdClass $metadata, stdClass $snapshot, array $snapshots): array
    {
        $peers = $this->links($metadata, 'peerDependencies');
        $peerOptional = $this->peers($metadata);
        $optional = $this->map($snapshot, 'optionalDependencies');
        $regular = $this->map($snapshot, 'dependencies');
        $requirements = [];
        $targets = [];

        foreach ([array_diff_key($regular, $optional), $optional] as $index => $links) {
            foreach ($links as $name => $reference) {
                $name = $this->packageName((string) $name);
                $target = $this->target($name, $reference, $snapshots);
                $targets[$name] = $target;

                if (! array_key_exists($name, $peers)) {
                    $requirements[] = new DependencyRequirement($id, $target, $name, $reference,
                        DependencyRequirementKind::Dependency, DependencyScope::Regular, $index === 1);
                }
            }
        }

        foreach ($peers as $name => $constraint) {
            if (isset($targets[$name])) {
                [, $identity] = $this->locator($targets[$name]);

                if ($identity !== $this->targetName((string) $name, $constraint)) {
                    $this->invalid();
                }
            }

            $requirements[] = new DependencyRequirement($id, $targets[$name] ?? null, (string) $name, $constraint,
                DependencyRequirementKind::Peer, DependencyScope::Regular, $peerOptional[$name]);
        }

        return $requirements;
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

    private function targetName(string $name, string $constraint): string
    {
        if (! str_starts_with($constraint, 'npm:')) {
            return $name;
        }

        if (preg_match('{^npm:((?:@[^/]+/)?[^@]+)(?:@(.+))?$}D', $constraint, $match) !== 1) {
            $this->invalid();
        }

        if (isset($match[2]) && preg_match('{[:/@?]}', $match[2]) === 1) {
            $this->invalid();
        }

        return $this->packageName($match[1]);
    }

    private function constraint(mixed $value): string
    {
        if (is_string($value) && $this->isRemote($value)) {
            $this->validateRemote($value);

            return $value;
        }

        if (! is_string($value) || preg_match('/[?\x00-\x1f\x7f\\\\]/', $value) === 1) {
            $this->invalid();
        }

        $this->rejectLocal($value);

        if (str_contains($value, '://')) {
            $url = parse_url($value);

            if ($url === false || isset($url['user']) || isset($url['pass']) || isset($url['query'])) {
                $this->invalid();
            }
        } elseif (str_contains($value, '@') && ! str_starts_with($value, 'npm:')) {
            $this->invalid();
        }

        $this->targetName('unused', $value);

        return $value;
    }

    private function isRemote(string $reference): bool
    {
        return preg_match('{^https?://}i', $reference) === 1;
    }

    private function validateRemote(string $reference): void
    {
        $url = parse_url($reference);

        if ($url === false || ! isset($url['host']) || $url['host'] === ''
            || preg_match('/[\\s\\x00-\\x1f\\x7f\\\\]/', $reference) === 1) {
            $this->invalid();
        }
    }

    private function safeReference(string $reference): string
    {
        return str_contains($reference, '://') ? 'pnpm:sha256:'.hash('sha256', $reference) : $reference;
    }

    private function rejectLocal(string $value): void
    {
        if (preg_match('{^(?:file:|link:|workspace:|catalog:|\.{1,2}/|/|~[/\\\\])}i', $value) === 1) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }
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
