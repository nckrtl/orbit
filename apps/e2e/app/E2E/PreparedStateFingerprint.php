<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\SerializedArrays;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use InvalidArgumentException;
use JsonException;

final readonly class PreparedStateFingerprint
{
    private const array ROOT_KEYS = [
        'schema',
        'paths',
        'cold_epoch',
        'base_image_alias',
        'operator_base_image_alias',
        'declared_epochs',
        'topology',
    ];

    public function __construct(
        private GitRepository $git,
        private string $manifestPath = 'apps/e2e/resources/prepared-state.json',
    ) {}

    public function forCommit(string $commit = 'HEAD', ?LaravelRelease $laravel = null): PreparedFingerprint
    {
        $sha = $this->git->commit($commit);
        $blob = $this->git->blobs($sha, [$this->manifestPath])[$this->manifestPath];

        try {
            $manifest = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The prepared-state manifest is invalid JSON.', previous: $exception);
        }

        $manifest = $this->validateManifest($manifest);
        $hashes = [];

        foreach ($this->git->blobs($sha, $manifest['paths']) as $path => $content) {
            $hashes[$path] = hash('sha256', $content);
        }

        $payload = $this->canonicalizeManifest([
            'schema' => $manifest['schema'],
            'paths' => $hashes,
            'cold_epoch' => $manifest['cold_epoch'],
            'base_image_alias' => $manifest['base_image_alias'],
            'operator_base_image_alias' => $manifest['operator_base_image_alias'],
            'declared_epochs' => $manifest['declared_epochs'],
            'topology' => $manifest['topology'],
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $structural = new PreparedFingerprint(hash('sha256', $encoded), $payload);

        return $laravel === null ? $structural : $this->withLaravel($structural, $laravel);
    }

    public function withLaravel(PreparedFingerprint $structural, LaravelRelease $laravel): PreparedFingerprint
    {
        $payload = $this->canonicalizeManifest($structural->manifest);
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expected = self::ROOT_KEYS;
        sort($expected, SORT_STRING);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($keys !== $expected || hash('sha256', $encoded) !== $structural->value) {
            throw new InvalidArgumentException('The structural prepared fingerprint is invalid.');
        }

        $payload['laravel_pin'] = ['tag' => $laravel->tag, 'commit' => $laravel->commit];
        $payload = $this->canonicalizeManifest($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new PreparedFingerprint(hash('sha256', $encoded), $payload);
    }

    /** @return array{schema: int, paths: list<string>, cold_epoch: string, base_image_alias: string, operator_base_image_alias: string, declared_epochs: array<string, int>, topology: array{profile: string, roles: list<string>, checkout_roles: list<string>, assignments: array<string, list<string>>}} */
    private function validateManifest(mixed $manifest): array
    {
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new InvalidArgumentException('The prepared-state manifest must be an object.');
        }

        $keys = array_keys($manifest);
        $expected = self::ROOT_KEYS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($keys !== $expected || $manifest['schema'] !== 2) {
            throw new InvalidArgumentException('The prepared-state manifest schema is invalid.');
        }

        if (($manifest['operator_base_image_alias'] ?? null) !== TopologyRecipe::OPERATOR_IMAGE) {
            throw new InvalidArgumentException('The operator base image alias is invalid.');
        }
        $paths = $this->validatePaths($manifest['paths']);
        $coldBase = $this->validateColdBase($manifest['cold_epoch'], $manifest['base_image_alias']);
        $declaredEpochs = $this->validateEpochs($manifest['declared_epochs']);
        $topology = $this->validateTopology($manifest['topology']);

        return [
            'schema' => 2,
            'paths' => $paths,
            'cold_epoch' => $coldBase['cold_epoch'],
            'base_image_alias' => $coldBase['base_image_alias'],
            'operator_base_image_alias' => $manifest['operator_base_image_alias'],
            'declared_epochs' => $declaredEpochs,
            'topology' => $topology,
        ];
    }

    /** @return list<string> */
    private function validatePaths(mixed $paths): array
    {
        if (! is_array($paths) || ! array_is_list($paths) || $paths === []) {
            throw new InvalidArgumentException('Prepared-state paths must be a non-empty list.');
        }

        $validated = [];
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                throw new InvalidArgumentException('Every prepared-state path must be a string.');
            }
            $validated[] = $path;
        }

        if (count($validated) !== count(array_unique($validated))) {
            throw new InvalidArgumentException('Prepared-state paths must be unique.');
        }

        return $validated;
    }

    /** @return array{cold_epoch: string, base_image_alias: string} */
    private function validateColdBase(mixed $coldEpoch, mixed $baseImageAlias): array
    {
        if (
            ! is_string($coldEpoch)
            || preg_match('/\Aubuntu-[0-9]{2}\.[0-9]{2}-(?:amd64|arm64)-v[1-9][0-9]*\z/D', $coldEpoch) !== 1
            || ! is_string($baseImageAlias)
            || strlen($baseImageAlias) > 63
            || preg_match('/\Aorbit-base-[A-Za-z0-9._-]+\z/D', $baseImageAlias) !== 1
        ) {
            throw new InvalidArgumentException('The prepared-state cold base contract is invalid.');
        }

        return ['cold_epoch' => $coldEpoch, 'base_image_alias' => $baseImageAlias];
    }

    /** @return array<string, int> */
    private function validateEpochs(mixed $declaredEpochs): array
    {
        if (! is_array($declaredEpochs) || array_is_list($declaredEpochs)) {
            throw new InvalidArgumentException('Declared epochs must be a non-empty object.');
        }

        $validated = [];
        foreach ($declaredEpochs as $name => $epoch) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) !== 1 || ! is_int($epoch) || $epoch < 1) {
                throw new InvalidArgumentException('Every declared epoch must have a valid name and value.');
            }
            $validated[$name] = $epoch;
        }

        return $validated;
    }

    /** @return array{profile: string, roles: list<string>, checkout_roles: list<string>, assignments: array<string, list<string>>} */
    private function validateTopology(mixed $topology): array
    {
        if (! is_array($topology) || array_is_list($topology)) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        $topology = SerializedArrays::stringKeyed($topology);
        $keys = array_keys($topology);
        sort($keys, SORT_STRING);

        if (
            $keys !== ['assignments', 'checkout_roles', 'profile', 'roles']
            || $topology['profile'] !== TopologyProfile::NAME
            || ! is_array($topology['roles'])
            || ! is_array($topology['checkout_roles'])
            || ! is_array($topology['assignments'])
        ) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        $roles = SerializedArrays::stringList($topology['roles']);
        $checkoutRoles = SerializedArrays::stringList($topology['checkout_roles']);
        $assignments = SerializedArrays::stringLists($topology['assignments']);

        if (
            ! $this->isExactOrderedList($roles, TopologyProfile::ROLES)
            || ! $this->isExactOrderedList($checkoutRoles, TopologyProfile::CHECKOUT_ROLES)
            || ! in_array($assignments, [TopologyProfile::ASSIGNMENTS, TopologyProfile::PREVIOUS_ASSIGNMENTS], true)
        ) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        return [
            'profile' => TopologyProfile::NAME,
            'roles' => $roles,
            'checkout_roles' => $checkoutRoles,
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     */
    private function isExactOrderedList(array $actual, array $expected): bool
    {
        if (count($actual) !== count($expected)) {
            return false;
        }

        return array_all($expected, fn ($value, $index) => ! ($actual[$index] !== $value));
    }

    /** @param array<array-key, mixed> $manifest
     * @return array<array-key, mixed>
     */
    private function canonicalizeManifest(array $manifest): array
    {
        $payload = $this->canonicalizeArray($manifest);
        $topology = $payload['topology'] ?? null;

        if (! is_array($topology) || array_is_list($topology)) {
            throw new InvalidArgumentException('The structural prepared fingerprint is invalid.');
        }

        $originalTopology = $manifest['topology'] ?? null;
        $originalAssignments = is_array($originalTopology) ? ($originalTopology['assignments'] ?? null) : null;
        if (! is_array($originalAssignments)) {
            throw new InvalidArgumentException('The structural prepared fingerprint is invalid.');
        }
        $topology['assignments'] = array_replace(
            array_fill_keys(TopologyProfile::ROLES, []),
            $originalAssignments,
        );
        $payload['topology'] = $topology;

        return $payload;
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function canonicalizeArray(array $value): array
    {
        $canonical = array_map(
            fn (mixed $item): mixed => is_array($item) ? $this->canonicalizeArray($item) : $item,
            $value,
        );

        if (array_is_list($canonical)) {
            return $canonical;
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
