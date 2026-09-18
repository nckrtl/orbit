<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\SelectDependencyInputAction;
use App\Domain\AppInstances\Dependencies\CollectedDependencyFiles;
use App\Domain\AppInstances\Dependencies\DependencyCollectionException;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Infrastructure\AppInstances\DependencyFilesProgram;

/** @param array<string, string> $contents @param array<string, string> $errors */
function collected_dependency_input(array $contents, array $errors = []): CollectedDependencyFiles
{
    $contents = array_replace(array_fill_keys(DependencyFilesProgram::FILES, null), $contents);

    return new CollectedDependencyFiles('/project', null, str_repeat('a', 64), $contents,
        array_map(fn (?string $value): ?string => $value === null ? null : hash('sha256', $value), $contents), $errors);
}

describe('root dependency input selection', function (): void {
    it('selects supported families without invoking a package manager', function (string $manifest, array $locks, string $manager, string $lock): void {
        $files = collected_dependency_input(['package.json' => $manifest, ...$locks]);

        $input = new SelectDependencyInputAction()->execute($files, DependencyEcosystem::Npm);

        expect($input->manager)->toBe($manager);
        expect($input->manifest)->toBe($manifest);
        expect($input->lockfileName)->toBe($lock);
        expect($input->lockfile)->toBe($locks[$lock]);
        expect($input->source->fileHashes['package.json'])->toBe(hash('sha256', $manifest));
    })->with([
        ['{}', ['package-lock.json' => 'npm'], 'npm', 'package-lock.json'],
        ['{}', ['package-lock.json' => 'ignored', 'npm-shrinkwrap.json' => 'chosen'], 'npm', 'npm-shrinkwrap.json'],
        ['{}', ['npm-shrinkwrap.json' => 'shrinkwrap'], 'npm', 'npm-shrinkwrap.json'],
        ['{}', ['pnpm-lock.yaml' => 'pnpm'], 'pnpm', 'pnpm-lock.yaml'],
        ['{}', ['bun.lock' => 'text', 'bun.lockb' => 'binary'], 'bun', 'bun.lock'],
        ['{"packageManager":"npm@11.0.0+sha512.abc"}', ['package-lock.json' => 'npm'], 'npm', 'package-lock.json'],
        ['{"devEngines":{"packageManager":{"name":"bun","version":"^1"}}}', ['bun.lock' => 'bun'], 'bun', 'bun.lock'],
        ['{"devEngines":{"packageManager":[{"name":"unknown"},{"name":"pnpm"}]}}', ['pnpm-lock.yaml' => 'pnpm'], 'pnpm', 'pnpm-lock.yaml'],
        ['{"devEngines":{"packageManager":{"name":"unknown","onFail":"warn"}}}', ['pnpm-lock.yaml' => 'pnpm'], 'pnpm', 'pnpm-lock.yaml'],
    ]);

    it('keeps complete Composer input independent of failed JavaScript files', function (): void {
        $files = collected_dependency_input(['composer.json' => '{}', 'composer.lock' => '{"packages":[]}'], ['package.json' => 'dependencies.unreadable_source']);

        $input = new SelectDependencyInputAction()->execute($files, DependencyEcosystem::Composer);

        expect($input->manager)->toBe('composer');
        expect($input->lockfileName)->toBe('composer.lock');
        expect(array_keys($input->source->fileHashes))->toBe(['composer.json', 'composer.lock']);
        expect(fn () => new SelectDependencyInputAction()->execute($files, DependencyEcosystem::Npm))->toThrow(DependencyCollectionException::class, 'dependencies.unreadable_source');
    });

    it('distinguishes verified absence in both ecosystems', function (DependencyEcosystem $ecosystem): void {
        $input = new SelectDependencyInputAction()->execute(collected_dependency_input([]), $ecosystem);

        expect($input->manifest)->toBeNull();
        expect($input->lockfile)->toBeNull();
        expect($input->manager)->toBeNull();
        expect($input->source->format)->toBeNull();
    })->with([DependencyEcosystem::Composer, DependencyEcosystem::Npm]);

    it('rejects incomplete, ambiguous, unsupported and invalid JavaScript source', function (array $contents, string $code): void {
        expect(fn () => new SelectDependencyInputAction()->execute(collected_dependency_input($contents), DependencyEcosystem::Npm))
            ->toThrow(DependencyCollectionException::class, $code);
    })->with([
        [['package.json' => '{}'], 'dependencies.incomplete_source'],
        [['package-lock.json' => '{}'], 'dependencies.incomplete_source'],
        [['package.json' => '{}', 'pnpm-lock.yaml' => '', 'package-lock.json' => ''], 'dependencies.ambiguous_manager'],
        [['package.json' => '{"packageManager":"npm@11.0.0"}', 'pnpm-lock.yaml' => ''], 'dependencies.ambiguous_manager'],
        [['package.json' => '{"packageManager":"npm@11.0.0","devEngines":{"packageManager":{"name":"pnpm"}}}', 'package-lock.json' => ''], 'dependencies.ambiguous_manager'],
        [['package.json' => '{}', 'yarn.lock' => ''], 'dependencies.unsupported_format'],
        [['package.json' => '{"packageManager":"yarn@4.0.0"}', 'package-lock.json' => ''], 'dependencies.unsupported_format'],
        [['package.json' => '{}', '.yarnrc.yml' => ''], 'dependencies.unsupported_format'],
        [['yarn.config.cjs' => ''], 'dependencies.unsupported_format'],
        [['package.json' => '{}', 'bun.lockb' => ''], 'dependencies.unsupported_format'],
        [['package.json' => '{"workspaces":[]}', 'package-lock.json' => ''], 'dependencies.unsupported_layout'],
        [['pnpm-workspace.yaml' => ''], 'dependencies.unsupported_layout'],
        [['package.json' => '{"packageManager":"unknown@1.0.0"}'], 'dependencies.unsupported_format'],
        [['package.json' => '{"packageManager":"npm@latest"}'], 'dependencies.unsupported_format'],
        [['package.json' => '{"packageManager":null}'], 'dependencies.unsupported_format'],
        [['package.json' => '{"devEngines":[]}'], 'dependencies.invalid_manifest'],
        [['package.json' => '{"devEngines":{"packageManager":{"name":"unknown"}}}'], 'dependencies.unsupported_format'],
        [['package.json' => '{"devEngines":{"packageManager":{"name":"npm","onFail":"oops"}}}'], 'dependencies.invalid_manifest'],
        [['package.json' => '{"devEngines":{"packageManager":{"name":5}}}'], 'dependencies.invalid_manifest'],
        [['package.json' => '{"packageManager":"yarn@1.0.0","packageManager":"npm@1.0.0"}'], 'dependencies.invalid_manifest'],
        [['package.json' => '[]'], 'dependencies.invalid_manifest'],
        [['package.json' => '{'], 'dependencies.invalid_manifest'],
        [['.pnpmfile.cjs' => ''], 'dependencies.incomplete_source'],
        [['package.json' => '{}', 'package-lock.json' => '', 'bunfig.toml' => ''], 'dependencies.ambiguous_manager'],
    ]);

    it('rejects an incomplete Composer pair', function (array $contents): void {
        expect(fn () => new SelectDependencyInputAction()->execute(collected_dependency_input($contents), DependencyEcosystem::Composer))
            ->toThrow(DependencyCollectionException::class, 'dependencies.incomplete_source');
    })->with([[['composer.json' => '{}']], [['composer.lock' => '{}']]]);
});
