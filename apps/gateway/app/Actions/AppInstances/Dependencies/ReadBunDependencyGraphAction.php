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

final readonly class ReadBunDependencyGraphAction
{
    public function execute(string $manifestContents, string $lockContents): DependencyGraph
    {
        try {
            if (str_starts_with($lockContents, '#!/usr/bin/env bun') || str_contains($lockContents, "\0")) {
                throw new DependencyParseException('dependencies.unsupported_format');
            }

            return $this->read($this->decode($manifestContents), $this->decode($this->jsonc($lockContents)));
        } catch (JsonException|InvalidArgumentException) {
            throw new DependencyParseException('dependencies.invalid_bun_input');
        }
    }

    private function invalid(): never
    {
        throw new DependencyParseException('dependencies.invalid_bun_input');
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

    private function jsonc(string $contents): string
    {
        $contents = str_starts_with($contents, "\xef\xbb\xbf") ? substr($contents, 3) : $contents;
        $json = '';
        $length = strlen($contents);

        for ($offset = 0; $offset < $length;) {
            if ($contents[$offset] === '"') {
                if (preg_match('/\G"(?:[^"\\\\]|\\\\.)*"/s', $contents, $match, offset: $offset) !== 1) {
                    $this->invalid();
                }

                $json .= $match[0];
                $offset += strlen($match[0]);
            } elseif (substr($contents, $offset, 2) === '//') {
                $end = strpos($contents, "\n", $offset + 2);
                $offset = $end === false ? $length : $end;
                $json .= ' ';
            } elseif (substr($contents, $offset, 2) === '/*') {
                $end = strpos($contents, '*/', $offset + 2);

                if ($end === false) {
                    $this->invalid();
                }

                $offset = $end + 2;
                $json .= ' ';
            } else {
                $json .= $contents[$offset++];
            }
        }

        // Preserve strings and only remove a comma after an actual value.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^ \t\r\n{}\[\],:"]+/s', $json, $matches);
        $tokens = $matches[0];
        $previous = null;

        foreach ($tokens as $index => $token) {
            if ($token === ',' && in_array($tokens[$index + 1] ?? null, [']', '}'], true)
                && $previous !== null && ! in_array($previous, ['[', '{', ',', ':'], true)) {
                $tokens[$index] = '';
            }

            $previous = $token;
        }

        return implode(' ', $tokens);
    }

    private function packageRecord(mixed $tuple): stdClass
    {
        if (! is_array($tuple) || ! is_string($tuple[0] ?? null)
            || preg_match('{^((?:@[^/]+/)?[^@]+)@(.+)$}D', $tuple[0], $match) !== 1) {
            $this->invalid();
        }

        $name = $this->packageName($match[1]);
        $reference = $match[2];
        $this->rejectLocal($reference);
        $isSource = $this->isSource($reference);
        $isGit = preg_match('{^(?:git(?:\+[^:]+)?:|github:|gitlab:|bitbucket:|git@)}i', $reference) === 1;
        $info = $tuple[$isSource ? 1 : 2] ?? null;
        $expected = $isSource ? ($isGit ? 3 : 2) : 4;

        if (count($tuple) !== $expected || ! $info instanceof stdClass
            || (! $isSource && ! is_string($tuple[1]))
            || ($isGit && ! is_string($tuple[2]))) {
            $this->invalid();
        }

        $this->optionalPeers($info);
        $record = new stdClass;

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'peerDependenciesMeta', 'workspaces', 'link'] as $field) {
            if (property_exists($info, $field)) {
                $record->{$field} = $info->{$field};
            }
        }

        $record->name = $name;
        $record->version = $isSource ? $this->sourceId($reference) : $this->version($reference);
        $record->sourceReference = null;

        if ($isGit && preg_match('/#([a-f0-9]{40})$/iD', $reference, $revision) === 1) {
            $record->sourceReference = $revision[1];
        }

        if (! $isSource) {
            $record->integrity = $tuple[3];
            $this->integrity($record);
        }

        return $record;
    }

    private function optionalPeers(stdClass $record): void
    {
        if (! property_exists($record, 'optionalPeers')) {
            return;
        }

        if (! is_array($record->optionalPeers) || property_exists($record, 'peerDependenciesMeta')) {
            $this->invalid();
        }

        $metadata = new stdClass;
        $peers = $this->links($record, 'peerDependencies');

        foreach ($record->optionalPeers as $name) {
            $name = $this->packageName($name);

            if (! array_key_exists($name, $peers) || property_exists($metadata, $name)) {
                $this->invalid();
            }

            $metadata->{$name} = (object) ['optional' => true];
        }

        $record->peerDependenciesMeta = $metadata;
    }

    private function read(stdClass $manifest, stdClass $lock): DependencyGraph
    {
        if (($lock->lockfileVersion ?? null) !== 1) {
            throw new DependencyParseException('dependencies.unsupported_format');
        }

        if (! ($lock->workspaces ?? null) instanceof stdClass) {
            $this->invalid();
        }

        $workspaces = get_object_vars($lock->workspaces);

        if (array_diff(array_keys($workspaces), ['']) !== []) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        $root = $workspaces[''] ?? null;

        if (! $root instanceof stdClass) {
            $this->invalid();
        }

        $this->optionalPeers($root);
        $rawPackages = property_exists($lock, 'packages') ? $lock->packages : new stdClass;

        if (! $rawPackages instanceof stdClass) {
            $this->invalid();
        }

        $packages = [];

        foreach (get_object_vars($rawPackages) as $path => $tuple) {
            $this->pathParts((string) $path);
            $packages[$path] = $this->packageRecord($tuple);
        }

        $this->validateRecord($manifest);
        $this->validateRecord($root);

        foreach (['name', 'version'] as $field) {
            if (property_exists($manifest, $field) && property_exists($root, $field) && $manifest->{$field} !== $root->{$field}) {
                $this->invalid();
            }
        }

        $requirements = $this->requirements($manifest, null, $packages);

        $normalized = $this->normalizeRoot($manifest);

        if (array_map(get_object_vars(...), $this->requirements($normalized, null, $packages)) !== array_map(get_object_vars(...), $this->requirements($root, null, $packages))) {
            $this->invalid();
        }

        ksort($packages, SORT_STRING);

        foreach ($packages as $location => $record) {
            $location = (string) $location;

            $this->pathParts($location);
            $parent = $this->parent($location);

            if ($parent !== '' && ! isset($packages[$parent])) {
                $this->invalid();
            }

            $this->validateRecord($record, false);
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
                id: (string) $location,
                package: new DependencyIdentity(DependencyEcosystem::Npm, $this->packageName($record->name)),
                version: $record->version,
                regular: isset($regular[$location]),
                development: isset($development[$location]),
                sourceReference: $record->sourceReference,
                integrity: $this->integrity($record),
            );
        }

        return new DependencyGraph(DependencyEcosystem::Npm, $resolutions, $requirements);
    }

    private function normalizeRoot(stdClass $manifest): stdClass
    {
        $root = clone $manifest;
        $regular = $this->links($manifest, 'dependencies');
        $development = $this->links($manifest, 'devDependencies');

        foreach ($this->links($manifest, 'optionalDependencies') as $name => $constraint) {
            if (! array_key_exists($name, $regular)) {
                unset($development[$name]);
            }
        }

        // Keep raw constraints; requirement construction sanitizes them once.
        $root->devDependencies = (object) array_intersect_key(
            get_object_vars($manifest->devDependencies ?? new stdClass),
            $development,
        );

        return $root;
    }

    private function validateRecord(stdClass $record, bool $root = true): void
    {
        if (property_exists($record, 'workspaces') || ($record->link ?? false) === true) {
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

        if ($root && property_exists($record, 'version')) {
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

    /**
     * @param  array<string, mixed>  $packages
     * @return list<DependencyRequirement>
     */
    private function requirements(stdClass $record, ?string $from, array $packages): array
    {
        $regular = $this->links($record, 'dependencies');
        $optional = $this->links($record, 'optionalDependencies');
        $peers = $this->links($record, 'peerDependencies');
        $metadata = property_exists($record, 'peerDependenciesMeta') ? $record->peerDependenciesMeta : new stdClass;

        if (! $metadata instanceof stdClass) {
            $this->invalid();
        }

        foreach (get_object_vars($metadata) as $name => $meta) {
            if (! isset($peers[$name]) || ! $meta instanceof stdClass
                || (property_exists($meta, 'optional') && ! is_bool($meta->optional))) {
                $this->invalid();
            }
        }

        $requirements = [];
        $sections = [
            [array_diff_key($regular, $optional), DependencyScope::Regular, DependencyRequirementKind::Dependency, false],
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
                    || ($scope === DependencyScope::Development && isset($optional[$name]) && ! isset($regular[$name]))
                    || ($kind === DependencyRequirementKind::Peer && ($metadata->{$name}->optional ?? false));
                $target = $this->resolve($from, $name, $packages);

                if ($target === null && ! $optionalEdge && $kind !== DependencyRequirementKind::Peer) {
                    $this->invalid();
                }

                if ($target !== null) {
                    $targetRecord = $packages[$target];

                    if (! $targetRecord instanceof stdClass
                        || (str_starts_with($constraint, 'npm:') && $this->packageName($targetRecord->name) !== $this->targetName($name, $constraint))) {
                        $this->invalid();
                    }
                }

                $requirements[] = new DependencyRequirement($from, $target, $name, $constraint, $kind, $scope, $optionalEdge);
            }
        }

        return $requirements;
    }

    /** @param array<string, mixed> $packages */
    private function resolve(?string $from, string $name, array $packages): ?string
    {
        $context = $from ?? '';

        while (true) {
            $candidate = ($context === '' ? '' : $context.'/').$name;

            if (array_key_exists($candidate, $packages)) {
                return $candidate;
            }

            if ($context === '') {
                return null;
            }

            $context = $this->parent($context);
        }
    }

    /** @return non-empty-list<string> */
    private function pathParts(string $path): array
    {
        $segments = explode('/', $path);
        $parts = [];

        while ($segments !== []) {
            $name = array_shift($segments);

            if (str_starts_with($name, '@')) {
                $name .= '/'.array_shift($segments);
            }

            $parts[] = $this->packageName($name);
        }

        return $parts;
    }

    private function parent(string $path): string
    {
        $parts = $this->pathParts($path);
        array_pop($parts);

        return implode('/', $parts);
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
        if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $value) === 1) {
            $this->invalid();
        }

        $this->rejectLocal($value);

        if ($this->isSource($value)) {
            return $this->sourceId($value);
        }

        if (preg_match('{[/?@:]}', $value) === 1 && ! str_starts_with($value, 'npm:')) {
            $this->invalid();
        }

        $this->targetName('unused', $value);

        return $value;
    }

    private function isSource(string $value): bool
    {
        return preg_match('{^(?:https?://|git(?:\+[^:]+)?://|git\+ssh:|github:|gitlab:|bitbucket:|git@|[^/@:]+/[^/]+)}i', $value) === 1;
    }

    private function sourceId(string $value): string
    {
        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $value) === 1) {
            $this->invalid();
        }

        if (str_contains($value, '://')) {
            $url = parse_url($value);

            if ($url === false || ! isset($url['host']) || $url['host'] === '') {
                $this->invalid();
            }
        }

        return 'bun:sha256:'.hash('sha256', $value);
    }

    private function rejectLocal(string $value): void
    {
        if (preg_match('{^(?:file:|link:|workspace:|root:|catalog:|\.{1,2}/|/|~[/\\\\])}i', $value) === 1) {
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
        if (! property_exists($record, 'integrity') || $record->integrity === '') {
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
