<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Canonical PHP runtime inventory shared by live collection and retained evidence.
 */
final readonly class PhpRuntimeInventory
{
    public const array ROLES = ['app-dev', 'gateway'];

    public const array RUNTIME_PACKAGES = [
        'php8.5-cli',
        'php8.5-fpm',
        'php8.5-common',
        'php8.5-curl',
        'php8.5-mbstring',
        'php8.5-sqlite3',
        'php8.5-xml',
    ];

    public const string PCOV_PACKAGE = 'php8.5-pcov';

    public const array PACKAGES = [...self::RUNTIME_PACKAGES, self::PCOV_PACKAGE];

    /** @var list<array{role:string,php_version:string,fpm_version:string,pcov_version:?string,package_versions:array<string,string>}> */
    public array $runtimes;

    /** @param array<array-key, mixed> $runtimes */
    private function __construct(array $runtimes, bool $pcovRequired)
    {
        $this->runtimes = $this->validate($runtimes, $pcovRequired);
    }

    /** @param array<string, mixed> $payloads */
    public static function runtimeOnlyPayloads(array $payloads): self
    {
        return new self(self::entries($payloads), false);
    }

    /** @param array<string, mixed> $payloads */
    public static function pcovRequiredPayloads(array $payloads): self
    {
        return new self(self::entries($payloads), true);
    }

    /** @param array<array-key, mixed> $runtimes */
    public static function pcovRequired(array $runtimes): self
    {
        return new self($runtimes, true);
    }

    /** @return list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> */
    public function pcovRuntimes(): array
    {
        $runtimes = [];
        foreach ($this->runtimes as $runtime) {
            if (! is_string($runtime['pcov_version'])) {
                throw new InvalidArgumentException('The observed PHP runtime inventory does not include PCOV.');
            }
            $runtimes[] = [
                'role' => $runtime['role'],
                'php_version' => $runtime['php_version'],
                'fpm_version' => $runtime['fpm_version'],
                'pcov_version' => $runtime['pcov_version'],
                'package_versions' => $runtime['package_versions'],
            ];
        }

        return $runtimes;
    }

    /**
     * @param  array<string, mixed>  $payloads
     * @return list<array<string, mixed>>
     */
    private static function entries(array $payloads): array
    {
        if (array_keys($payloads) !== self::ROLES) {
            throw new InvalidArgumentException('The observed PHP runtime inventory is incomplete.');
        }

        $runtimes = [];
        foreach (self::ROLES as $role) {
            $payload = $payloads[$role];
            if (
                ! is_array($payload)
                || array_keys($payload) !== ['php_version', 'fpm_version', 'pcov_version', 'package_versions']
                || ! is_array($payload['package_versions'])
            ) {
                throw new InvalidArgumentException('An observed PHP runtime entry is invalid.');
            }
            $runtimes[] = ['role' => $role, ...$payload];
        }

        return $runtimes;
    }

    /**
     * @param  array<array-key, mixed>  $runtimes
     * @return list<array{role:string,php_version:string,fpm_version:string,pcov_version:?string,package_versions:array<string,string>}>
     */
    private function validate(array $runtimes, bool $pcovRequired): array
    {
        if (! array_is_list($runtimes)) {
            throw new InvalidArgumentException('The observed PHP runtime inventory is invalid.');
        }

        $packages = $pcovRequired ? self::PACKAGES : self::RUNTIME_PACKAGES;
        $shared = null;
        $validatedRuntimes = [];
        foreach ($runtimes as $index => $runtime) {
            if (! is_array($runtime)) {
                throw new InvalidArgumentException('An observed PHP runtime entry is invalid.');
            }
            $runtime = SerializedArrays::stringKeyed($runtime);
            $packageVersions = $runtime['package_versions'] ?? null;
            $versions = [];
            $normalizedVersions = [];
            $packageVersionsValid = is_array($packageVersions) && array_keys($packageVersions) === $packages;
            if ($packageVersionsValid) {
                foreach ($packageVersions as $package => $version) {
                    if (! is_string($package)) {
                        $packageVersionsValid = false;
                        break;
                    }
                    if (
                        ! is_string($version)
                        || $version === ''
                        || str_contains($version, "\r")
                        || str_contains($version, "\n")
                    ) {
                        $packageVersionsValid = false;
                        break;
                    }
                    $versions[] = $version;
                    $normalizedVersions[$package] = $version;
                }
            }
            $phpVersion = $runtime['php_version'] ?? null;
            $fpmVersion = $runtime['fpm_version'] ?? null;
            $pcovVersion = $runtime['pcov_version'] ?? null;
            if (
                array_keys($runtime) !== [
                    'role',
                    'php_version',
                    'fpm_version',
                    'pcov_version',
                    'package_versions',
                ]
                || ($runtime['role'] ?? null) !== (self::ROLES[$index] ?? null)
                || ! is_string($phpVersion)
                || preg_match('/\A8\.5\.[0-9]+(?:[^\r\n]*)?\z/D', $phpVersion) !== 1
                || ! is_string($fpmVersion)
                || $fpmVersion !== $phpVersion
                || (
                    $pcovRequired
                        ? ! is_string($pcovVersion)
                        || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[^\r\n]*)?\z/D', $pcovVersion) !== 1
                        : $pcovVersion !== null
                )
                || ! $packageVersionsValid
                || count(array_unique(array_slice($versions, 0, count(self::RUNTIME_PACKAGES)))) !== 1
            ) {
                throw new InvalidArgumentException('An observed PHP runtime entry is invalid.');
            }

            $comparison = $runtime;
            unset($comparison['role']);
            if ($shared !== null && $comparison !== $shared) {
                throw new InvalidArgumentException('The observed PHP runtime inventories are not identical.');
            }
            $shared = $comparison;
            $validatedRuntimes[] = [
                'role' => self::ROLES[$index],
                'php_version' => $phpVersion,
                'fpm_version' => $fpmVersion,
                // The check above leaves pcov_version a string when PCOV is required and null otherwise.
                'pcov_version' => is_string($pcovVersion) ? $pcovVersion : null,
                'package_versions' => $normalizedVersions,
            ];
        }
        if (count($runtimes) !== count(self::ROLES)) {
            throw new InvalidArgumentException('The observed PHP runtime inventory is incomplete.');
        }

        return $validatedRuntimes;
    }
}
