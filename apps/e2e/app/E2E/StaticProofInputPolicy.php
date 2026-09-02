<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ProofInputClassification;

/**
 * Repository-owned phase-one classification for every proof-relevant component.
 *
 * @mago-expect lint:cyclomatic-complexity The positive path policy remains visible and fails closed in one place.
 */
final readonly class StaticProofInputPolicy
{
    public const int VERSION = 1;

    /** Runtime directories whose complete tracked contents execute in the topology. */
    private const array RUNTIME_DIRECTORIES = [
        'apps/cli/app/',
        'apps/cli/bootstrap/',
        'apps/cli/config/',
        'apps/gateway/app/',
        'apps/gateway/bootstrap/',
        'apps/gateway/config/',
        'apps/gateway/database/',
        'apps/gateway/public/',
        'apps/gateway/resources/',
        'apps/gateway/routes/',
        'apps/e2e/app/',
        'apps/e2e/bootstrap/',
        'apps/e2e/config/',
        'apps/e2e/resources/',
        'packages/php-sdk/src/',
    ];

    /** Runtime files that sit directly inside a governed component. */
    private const array RUNTIME_FILES = [
        'apps/cli/composer.json',
        'apps/cli/composer.lock',
        'apps/cli/orbit',
        'apps/gateway/.env.example',
        'apps/gateway/artisan',
        'apps/gateway/composer.json',
        'apps/gateway/composer.lock',
        'apps/e2e/artisan',
        'apps/e2e/composer.json',
        'apps/e2e/composer.lock',
        'packages/php-sdk/composer.json',
        'packages/php-sdk/composer.lock',
    ];

    private const array GOVERNED_DIRECTORIES = [
        'apps/cli/',
        'apps/gateway/',
        'apps/e2e/',
        'packages/php-sdk/',
        'bin/',
        'proofs/',
    ];

    private const array NON_RUNTIME_ROOT_FILES = [
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
        'AGENTS.md',
        'README.md',
        'composer.json',
        'composer.lock',
    ];

    public function classify(string $path): ProofInputClassification
    {
        if ($this->isInstruction($path) || $this->isReadme($path)) {
            return ProofInputClassification::NonRuntime;
        }
        if (
            str_starts_with($path, 'docs/')
            || str_starts_with($path, 'apps/docs/')
            || str_starts_with($path, '.github/')
        ) {
            return ProofInputClassification::NonRuntime;
        }
        if (in_array($path, self::NON_RUNTIME_ROOT_FILES, true)) {
            return ProofInputClassification::NonRuntime;
        }
        if (str_starts_with($path, 'proofs/')) {
            return ProofInputClassification::NonRuntime;
        }
        if (str_starts_with($path, 'bin/e2e-')) {
            return ProofInputClassification::Runtime;
        }
        if (str_starts_with($path, 'bin/')) {
            return ProofInputClassification::NonRuntime;
        }
        if ($this->isTestOrTooling($path)) {
            return ProofInputClassification::NonRuntime;
        }
        if (in_array($path, self::RUNTIME_FILES, true)) {
            return ProofInputClassification::Runtime;
        }
        foreach (self::RUNTIME_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return ProofInputClassification::Runtime;
            }
        }
        foreach (self::GOVERNED_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return ProofInputClassification::Indeterminate;
            }
        }

        return ProofInputClassification::Indeterminate;
    }

    public function allowsLiteralPath(string $path): bool
    {
        if ($this->classify($path) === ProofInputClassification::Runtime) {
            return true;
        }

        return in_array(
            rtrim($path, '/'),
            [
                'apps/cli',
                'apps/gateway',
                'apps/e2e',
                'packages/php-sdk',
            ],
            true,
        );
    }

    private function isInstruction(string $path): bool
    {
        return (
            basename($path) === 'AGENTS.md'
            || preg_match('~(?:\A|/)(?:\.agents|\.ai|\.codex)(?:/|\z)~D', $path) === 1
        );
    }

    private function isReadme(string $path): bool
    {
        return preg_match('~(?:\A|/)README(?:\.[^/]*)?\z~iD', $path) === 1;
    }

    private function isTestOrTooling(string $path): bool
    {
        if (preg_match('~(?:\A|/)tests(?:/|\z)~D', $path) === 1) {
            return true;
        }
        if (preg_match('~(?:\A|/)storage/(?:.+/)?\.gitignore\z~D', $path) === 1) {
            return true;
        }

        return (
            preg_match(
                '~(?:\A|/)(?:\.editorconfig|\.gitattributes|\.gitignore|boost\.json|mago\.toml|phpunit\.xml(?:\.dist)?|rector\.php)\z~D',
                $path,
            ) === 1
            || $path === 'apps/e2e/.env.example'
        );
    }
}
