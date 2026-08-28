<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\PreparedFingerprint;
use InvalidArgumentException;
use JsonException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Manifest validation stays at one trust boundary. */
final readonly class PreparedStateFingerprint
{
    private const array ROOT_KEYS = [
        'schema',
        'paths',
        'cold_epoch',
        'base_image_alias',
        'declared_epochs',
        'laravel_pin',
        'topology',
    ];

    public function __construct(
        private GitRepository $git,
        private string $manifestPath = 'apps/e2e/resources/prepared-state.json',
    ) {}

    public function forCommit(string $commit = 'HEAD'): PreparedFingerprint
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

        $payload = $this->canonicalizeArray([
            'schema' => $manifest['schema'],
            'paths' => $hashes,
            'cold_epoch' => $manifest['cold_epoch'],
            'base_image_alias' => $manifest['base_image_alias'],
            'declared_epochs' => $manifest['declared_epochs'],
            'laravel_pin' => $manifest['laravel_pin'],
            'topology' => $manifest['topology'],
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new PreparedFingerprint(hash('sha256', $encoded), $payload);
    }

    /** @return array{schema: int, paths: list<string>, cold_epoch: string, base_image_alias: string, declared_epochs: array<string, int>, laravel_pin: array{tag: string, commit: string}, topology: array{profile: string, roles: list<string>, checkout_roles: list<string>}} */
    private function validateManifest(mixed $manifest): array
    {
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new InvalidArgumentException('The prepared-state manifest must be an object.');
        }

        $keys = array_keys($manifest);
        $expected = self::ROOT_KEYS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($keys !== $expected || $manifest['schema'] !== 1) {
            throw new InvalidArgumentException('The prepared-state manifest schema is invalid.');
        }

        $this->validatePaths($manifest['paths']);
        $this->validateColdBase($manifest['cold_epoch'], $manifest['base_image_alias']);
        $this->validateEpochs($manifest['declared_epochs']);
        $this->validateLaravelPin($manifest['laravel_pin']);
        $this->validateTopology($manifest['topology']);

        /** @var array{schema: int, paths: list<string>, cold_epoch: string, base_image_alias: string, declared_epochs: array<string, int>, laravel_pin: array{tag: string, commit: string}, topology: array{profile: string, roles: list<string>, checkout_roles: list<string>}} $manifest */
        return $manifest;
    }

    private function validatePaths(mixed $paths): void
    {
        if (! is_array($paths) || ! array_is_list($paths) || $paths === []) {
            throw new InvalidArgumentException('Prepared-state paths must be a non-empty list.');
        }

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                throw new InvalidArgumentException('Every prepared-state path must be a string.');
            }
        }

        if (count($paths) !== count(array_unique($paths))) {
            throw new InvalidArgumentException('Prepared-state paths must be unique.');
        }
    }

    private function validateColdBase(mixed $coldEpoch, mixed $baseImageAlias): void
    {
        if (
            $coldEpoch !== 'ubuntu-26.04-amd64-v1'
            || $baseImageAlias !== 'orbit-base-ubuntu-26.04-runtime'
        ) {
            throw new InvalidArgumentException('The prepared-state cold base contract is invalid.');
        }
    }

    private function validateEpochs(mixed $declaredEpochs): void
    {
        if (! is_array($declaredEpochs) || array_is_list($declaredEpochs) || $declaredEpochs === []) {
            throw new InvalidArgumentException('Declared epochs must be a non-empty object.');
        }

        foreach ($declaredEpochs as $name => $epoch) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/D', (string) $name) !== 1 || ! is_int($epoch) || $epoch < 1) {
                throw new InvalidArgumentException('Every declared epoch must have a valid name and value.');
            }
        }
    }

    private function validateLaravelPin(mixed $pin): void
    {
        if (! is_array($pin) || array_is_list($pin)) {
            throw new InvalidArgumentException('The Laravel pin schema is invalid.');
        }

        $keys = array_keys($pin);
        sort($keys, SORT_STRING);

        if ($keys !== ['commit', 'tag']) {
            throw new InvalidArgumentException('The Laravel pin schema is invalid.');
        }

        if (! is_string($pin['tag']) || preg_match('/\Av\d+\.\d+\.\d+\z/D', $pin['tag']) !== 1) {
            throw new InvalidArgumentException('The Laravel pin tag is invalid.');
        }

        if (! is_string($pin['commit']) || preg_match('/\A[0-9a-f]{40}\z/D', $pin['commit']) !== 1) {
            throw new InvalidArgumentException('The Laravel pin commit is invalid.');
        }
    }

    private function validateTopology(mixed $topology): void
    {
        if (! is_array($topology) || array_is_list($topology)) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        $keys = array_keys($topology);
        sort($keys, SORT_STRING);

        if ($keys !== ['checkout_roles', 'profile', 'roles'] || $topology['profile'] !== 'gateway_app-dev_app-prod') {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        $roles = $topology['roles'];
        $checkoutRoles = $topology['checkout_roles'];

        if (
            ! is_array($roles)
            || ! array_is_list($roles)
            || ! is_array($checkoutRoles)
            || ! array_is_list($checkoutRoles)
        ) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }

        foreach ([...$roles, ...$checkoutRoles] as $role) {
            if (! is_string($role)) {
                throw new InvalidArgumentException('The prepared-state topology is invalid.');
            }
        }

        /** @var list<string> $roles */
        /** @var list<string> $checkoutRoles */

        sort($roles, SORT_STRING);
        sort($checkoutRoles, SORT_STRING);

        if ($roles !== ['app-dev', 'app-prod', 'gateway'] || $checkoutRoles !== ['app-dev', 'gateway']) {
            throw new InvalidArgumentException('The prepared-state topology is invalid.');
        }
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
            usort($canonical, fn (mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ));

            return $canonical;
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
