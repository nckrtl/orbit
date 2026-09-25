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
use App\Domain\AppInstances\Dependencies\NpmVersionRange;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class ReadNpmDependencyGraphAction
{
    public function execute(string $manifestContents, string $lockContents): DependencyGraph
    {
        try {
            return $this->read($this->decode($manifestContents), $this->decode($lockContents));
        } catch (JsonException|InvalidArgumentException) {
            throw new DependencyParseException('dependencies.invalid_npm_input');
        }
    }

    private function invalid(): never
    {
        throw new DependencyParseException('dependencies.invalid_npm_input');
    }

    private function stale(): never
    {
        throw new DependencyParseException('dependencies.stale_npm_lockfile');
    }

    /** @return array<string, mixed> */
    private function declarations(stdClass $record): array
    {
        $declarations = [];

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $field) {
            $declarations[$field] = $this->links($record, $field);
        }

        // npm drops a regular declaration that optionalDependencies repeats.
        $declarations['dependencies'] = array_diff_key($declarations['dependencies'], $declarations['optionalDependencies']);

        $metadata = $record->peerDependenciesMeta ?? null;

        foreach (array_keys($declarations['peerDependencies']) as $name) {
            $declarations['optionalPeers'][$name] = $metadata instanceof stdClass && ($metadata->{$name}->optional ?? false) === true;
        }

        return $declarations;
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

    private function read(stdClass $manifest, stdClass $lock): DependencyGraph
    {
        if (property_exists($lock, 'workspaces')) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        if (! in_array($lock->lockfileVersion ?? null, [2, 3], true)) {
            throw new DependencyParseException('dependencies.unsupported_format');
        }

        if (! ($lock->packages ?? null) instanceof stdClass) {
            $this->invalid();
        }

        $packages = get_object_vars($lock->packages);
        $root = $packages === [] ? new stdClass : ($packages[''] ?? null);

        if (! $root instanceof stdClass) {
            $this->invalid();
        }

        $this->validateRecord($manifest, projectRoot: true);
        $this->validateRecord($root, projectRoot: true);

        foreach (['name', 'version'] as $field) {
            if (property_exists($manifest, $field) && property_exists($root, $field) && $manifest->{$field} !== $root->{$field}) {
                $this->stale();
            }
        }

        // npm install rewrites the root record from package.json, so any declaration difference is a stale lock.
        if ($this->declarations($manifest) !== $this->declarations($root)) {
            $this->stale();
        }

        $requirements = $this->requirements($manifest, null, $packages);

        if (array_map(get_object_vars(...), $requirements) !== array_map(get_object_vars(...), $this->requirements($root, null, $packages))) {
            $this->invalid();
        }

        unset($packages['']);
        ksort($packages, SORT_STRING);

        foreach ($packages as $location => $record) {
            if (! is_string($location) || ! $record instanceof stdClass) {
                $this->invalid();
            }

            $this->locationName($location);
            $parent = $this->parent($location);

            if ($parent !== '' && ! isset($packages[$parent])) {
                $this->invalid();
            }

            $this->validateRecord($record);
            array_push($requirements, ...$this->requirements($record, $location, $packages));
        }

        $regular = $this->reachable($requirements, DependencyScope::Regular);
        $development = $this->reachable($requirements, DependencyScope::Development);
        $resolutions = [];

        foreach ($packages as $location => $record) {
            if (! isset($regular[$location]) && ! isset($development[$location])) {
                $this->invalid();
            }

            $resolutions[] = new DependencyResolution(
                id: $location,
                package: new DependencyIdentity(DependencyEcosystem::Npm, $this->identity($location, $record)),
                version: $this->version($record->version ?? null),
                regular: isset($regular[$location]),
                development: isset($development[$location]),
                sourceReference: $this->sourceReference($record),
                integrity: $this->integrity($record),
            );
        }

        return new DependencyGraph(DependencyEcosystem::Npm, $resolutions, $requirements);
    }

    private function validateRecord(stdClass $record, bool $projectRoot = false): void
    {
        if (($projectRoot && property_exists($record, 'workspaces')) || ($record->link ?? false) === true) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        foreach (['link', 'dev', 'optional', 'devOptional', 'peer', 'inBundle', 'hasInstallScript'] as $field) {
            if (property_exists($record, $field) && ! is_bool($record->{$field})) {
                $this->invalid();
            }
        }

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $field) {
            $this->links($record, $field);
        }

        if (property_exists($record, 'name')) {
            $this->packageName($record->name);
        }

        if (property_exists($record, 'version')) {
            $this->version($record->version);
        }

        if (property_exists($record, 'resolved')) {
            if (! is_string($record->resolved)) {
                $this->invalid();
            }

            $this->rejectLocal($record->resolved);
        }
    }

    /** @return array<array-key, string> */
    private function links(stdClass $record, string $field): array
    {
        if (! property_exists($record, $field)) {
            return [];
        }

        if (! $record->{$field} instanceof stdClass) {
            $this->invalid();
        }

        $links = [];

        foreach (get_object_vars($record->{$field}) as $name => $constraint) {
            $links[$this->packageName((string) $name)] = $this->constraint($constraint);
        }

        ksort($links, SORT_STRING);

        return $links;
    }

    /** @return array<string, true> */
    private function bundledNames(stdClass $record): array
    {
        $names = [];

        foreach (['bundleDependencies', 'bundledDependencies'] as $field) {
            if (! property_exists($record, $field)) {
                continue;
            }

            if (! is_array($record->{$field})) {
                $this->invalid();
            }

            foreach ($record->{$field} as $name) {
                if (! is_string($name)) {
                    $this->invalid();
                }

                $names[$this->packageName($name)] = true;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return list<DependencyRequirement>
     */
    private function requirements(stdClass $record, ?string $from, array $packages): array
    {
        $optional = $this->links($record, 'optionalDependencies');
        $peers = $this->links($record, 'peerDependencies');
        $metadata = property_exists($record, 'peerDependenciesMeta') ? $record->peerDependenciesMeta : new stdClass;

        if (! $metadata instanceof stdClass) {
            $this->invalid();
        }

        // npm reads metadata only for declared peers; published packages such as debug carry metadata alone.
        foreach (get_object_vars($metadata) as $name => $meta) {
            if (! $meta instanceof stdClass
                || (property_exists($meta, 'optional') && ! is_bool($meta->optional))) {
                $this->invalid();
            }
        }

        $bundled = $this->bundledNames($record);
        $requirements = [];
        $sections = [
            [array_diff_key($this->links($record, 'dependencies'), $optional), DependencyScope::Regular, DependencyRequirementKind::Dependency, false],
            [$optional, DependencyScope::Regular, DependencyRequirementKind::Dependency, true],
            [$peers, DependencyScope::Regular, DependencyRequirementKind::Peer, false],
        ];

        if ($from === null) {
            $sections[] = [$this->links($record, 'devDependencies'), DependencyScope::Development, DependencyRequirementKind::Dependency, false];
        }

        foreach ($sections as [$links, $scope, $kind, $isOptional]) {
            foreach ($links as $name => $constraint) {
                $name = (string) $name;
                $optionalEdge = $isOptional
                    || isset($bundled[$name])
                    || ($kind === DependencyRequirementKind::Peer && ($metadata->{$name}->optional ?? false));
                $target = $this->resolve($from, $name, $kind, $packages);

                if ($target === null && ! $optionalEdge && $kind !== DependencyRequirementKind::Peer) {
                    $this->invalid();
                }

                if ($target !== null) {
                    $targetRecord = $packages[$target];

                    if (! $targetRecord instanceof stdClass
                        || (str_starts_with($constraint, 'npm:') && ! $this->aliasTarget($name, $constraint, $target, $targetRecord))) {
                        $this->invalid();
                    }
                }

                $requirements[] = new DependencyRequirement($from, $target, $name, $constraint, $kind, $scope, $optionalEdge);
            }
        }

        return $requirements;
    }

    /** @param array<string, mixed> $packages */
    private function resolve(?string $from, string $name, DependencyRequirementKind $kind, array $packages): ?string
    {
        $context = $from ?? '';

        if ($kind === DependencyRequirementKind::Peer && $context !== '') {
            $context = $this->parent($context);
        }

        while (true) {
            $candidate = ($context === '' ? '' : $context.'/').'node_modules/'.$name;

            if (array_key_exists($candidate, $packages)) {
                return $candidate;
            }

            if ($context === '') {
                return null;
            }

            $context = $this->parent($context);
        }
    }

    private function locationName(string $location): string
    {
        $parts = explode('/node_modules/', '/'.$location);

        if (array_shift($parts) !== '' || $parts === []) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        foreach ($parts as $part) {
            $this->packageName($part);
        }

        return array_last($parts);
    }

    private function parent(string $location): string
    {
        $offset = strrpos($location, '/node_modules/');

        return $offset === false ? '' : substr($location, 0, $offset);
    }

    private function identity(string $location, stdClass $record): string
    {
        return property_exists($record, 'name') ? $this->packageName($record->name) : $this->locationName($location);
    }

    private function packageName(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 214
            || preg_match('{^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$}iD', $value) !== 1) {
            $this->invalid();
        }

        return $value;
    }

    /**
     * npm validates an alias edge against the spec after the package name and never compares that name.
     * A dist-tag accepts any registry tarball, so npm can lock the unaliased package, for example with Vite+ overrides.
     */
    private function aliasTarget(string $name, string $constraint, string $location, stdClass $record): bool
    {
        if ($this->identity($location, $record) === $this->targetName($name, $constraint)) {
            return true;
        }

        preg_match('{^npm:(?:@[^/]+/)?[^@]+(?:@(.+))?$}D', $constraint, $match);
        $spec = trim($match[1] ?? '');

        // npm-package-arg reads a bare alias without a spec as `*`, which dep-valid accepts without a semver check.
        if ($spec === '' || $spec === '*') {
            return true;
        }

        $range = NpmVersionRange::parse($spec);

        if ($range === null) {
            return is_string($record->resolved ?? null) && preg_match('{^https?://}i', $record->resolved) === 1;
        }

        return is_string($record->version ?? null) && $range->satisfies($record->version);
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

    private function rejectLocal(string $value): void
    {
        if (preg_match('{^(?:file:|link:|workspace:|\.{1,2}/|/|~[/\\\\])}i', $value) === 1) {
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

    private function sourceReference(stdClass $record): ?string
    {
        $resolved = $record->resolved ?? '';

        if (is_string($resolved) && preg_match('/^git(?:\+[^:]+)?:.*#([a-f0-9]{40})$/iD', $resolved, $match) === 1) {
            return $match[1];
        }

        return null;
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
