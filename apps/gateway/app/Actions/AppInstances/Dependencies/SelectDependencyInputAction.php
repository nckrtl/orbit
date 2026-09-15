<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\CollectedDependencyFiles;
use App\Domain\AppInstances\Dependencies\DependencyCollectionException;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyInput;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Infrastructure\AppInstances\DependencyFilesProgram;
use JsonException;
use stdClass;

final readonly class SelectDependencyInputAction
{
    private const array SIGNALS = [
        'npm-shrinkwrap.json' => 'npm', 'package-lock.json' => 'npm',
        'pnpm-lock.yaml' => 'pnpm', 'bun.lock' => 'bun', 'bun.lockb' => 'bun',
        'yarn.lock' => 'yarn', '.yarnrc.yml' => 'yarn',
        '.pnpmfile.cjs' => 'pnpm', 'pnpmfile.cjs' => 'pnpm',
        'bunfig.toml' => 'bun', 'yarn.config.cjs' => 'yarn',
    ];

    public function execute(CollectedDependencyFiles $files, DependencyEcosystem $ecosystem): DependencyInput
    {
        $names = $ecosystem === DependencyEcosystem::Composer
            ? ['composer.json', 'composer.lock']
            : array_values(array_diff(DependencyFilesProgram::FILES, ['composer.json', 'composer.lock']));
        foreach ($names as $name) {
            if (isset($files->errors[$name])) {
                throw new DependencyCollectionException($files->errors[$name]);
            }
            if (! array_key_exists($name, $files->contents) || ! array_key_exists($name, $files->hashes)) {
                throw new DependencyCollectionException('dependencies.invalid_collection');
            }
        }
        $manifestName = $ecosystem === DependencyEcosystem::Composer ? 'composer.json' : 'package.json';
        $manifest = $files->contents[$manifestName];
        $metadata = $manifest !== null ? $this->decode($manifest) : null;
        if ($metadata !== null && property_exists($metadata, 'workspaces')) {
            throw new DependencyCollectionException('dependencies.unsupported_layout');
        }
        if ($ecosystem === DependencyEcosystem::Composer) {
            $manager = 'composer';
            $lockName = 'composer.lock';
        } else {
            if ($files->contents['pnpm-workspace.yaml'] !== null) {
                throw new DependencyCollectionException('dependencies.unsupported_layout');
            }
            $signals = [];
            foreach (self::SIGNALS as $name => $family) {
                if ($files->contents[$name] !== null) {
                    $signals[] = $family;
                }
            }
            $declared = $metadata !== null ? $this->declaredManagers($metadata) : [];
            $signals = array_values(array_unique([...$declared, ...$signals]));
            if (in_array('yarn', $signals, true)) {
                throw new DependencyCollectionException('dependencies.unsupported_format');
            }
            if (count($signals) > 1) {
                throw new DependencyCollectionException('dependencies.ambiguous_manager');
            }
            $manager = $signals[0] ?? 'pnpm';
            $lockName = match ($manager) {
                'npm' => $files->contents['npm-shrinkwrap.json'] !== null ? 'npm-shrinkwrap.json' : 'package-lock.json',
                'bun' => 'bun.lock',
                default => 'pnpm-lock.yaml',
            };
            if ($manager === 'bun' && $files->contents['bun.lock'] === null && $files->contents['bun.lockb'] !== null) {
                throw new DependencyCollectionException('dependencies.unsupported_format');
            }
            if ($manifest === null && $signals !== [] && $files->contents[$lockName] === null) {
                throw new DependencyCollectionException('dependencies.incomplete_source');
            }
        }
        $lock = $files->contents[$lockName];
        if (($manifest === null) !== ($lock === null)) {
            throw new DependencyCollectionException('dependencies.incomplete_source');
        }
        $absent = $manifest === null;

        return new DependencyInput(
            $ecosystem, $absent ? null : $manager, $manifest, $lock, $absent ? null : $lockName,
            new DependencySource($files->projectRoot, $files->reference, array_intersect_key($files->hashes, array_flip(array_values(array_filter($names, static fn (string $name): bool => ! str_starts_with($name, '.'))))), null),
        );
    }

    /** @return list<string> */
    private function declaredManagers(stdClass $manifest): array
    {
        $managers = [];
        if (property_exists($manifest, 'packageManager')) {
            if (! is_string($manifest->packageManager)
                || preg_match('/\A(npm|pnpm|bun|yarn)@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D', $manifest->packageManager, $match) !== 1) {
                throw new DependencyCollectionException('dependencies.unsupported_format');
            }
            $managers[] = $match[1];
        }
        if (property_exists($manifest, 'devEngines')) {
            if (! $manifest->devEngines instanceof stdClass) {
                throw new DependencyCollectionException('dependencies.invalid_manifest');
            }
            if (property_exists($manifest->devEngines, 'packageManager')) {
                $entries = $manifest->devEngines->packageManager;
                $entries = is_array($entries) ? $entries : [$entries];
                $selected = null;
                $onFail = 'error';
                foreach ($entries as $entry) {
                    if (! $entry instanceof stdClass || ! is_string($entry->name ?? null)
                        || (isset($entry->version) && ! is_string($entry->version))
                        || (isset($entry->onFail) && ! in_array($entry->onFail, ['ignore', 'warn', 'error', 'download'], true))) {
                        throw new DependencyCollectionException('dependencies.invalid_manifest');
                    }
                    $onFail = $entry->onFail ?? 'error';
                    if (in_array($entry->name, ['npm', 'pnpm', 'bun', 'yarn'], true)) {
                        $selected = $entry->name;
                        break;
                    }
                }
                if ($selected !== null) {
                    $managers[] = $selected;
                } elseif (! in_array($onFail, ['warn', 'ignore'], true)) {
                    throw new DependencyCollectionException('dependencies.unsupported_format');
                }
            }
        }

        return $managers;
    }

    private function decode(string $contents): stdClass
    {
        try {
            $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if (! $value instanceof stdClass) {
                throw new DependencyCollectionException('dependencies.invalid_manifest');
            }
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
                        throw new DependencyCollectionException('dependencies.invalid_manifest');
                    }
                    $objects[$index][$key] = true;
                }
            }

            return $value;
        } catch (JsonException) {
            throw new DependencyCollectionException('dependencies.invalid_manifest');
        }
    }
}
