<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\Tools\ComposerDryRunVersionParser;

describe(ComposerDryRunVersionParser::class, function (): void {
    it('returns the exact target destination version', function (string $output, string $expected): void {
        $version = new ComposerDryRunVersionParser()->parse(
            output: $output,
            package: 'laravel/installer',
            installedFallback: static fn (): ?string => 'v5.15.0',
        );

        expect($version)->toBe($expected);
    })->with([
        'locking' => ['  - Locking laravel/installer (v5.17.0)', 'v5.17.0'],
        'installing' => ['  - Installing laravel/installer (v5.17.0)', 'v5.17.0'],
        'upgrading' => ['  - Upgrading laravel/installer (v5.16.0 => v5.17.0)', 'v5.17.0'],
        'downgrading' => ['  - Downgrading laravel/installer (v5.17.0 => v5.16.0)', 'v5.16.0'],
    ]);

    it('returns the verified installed fallback for an exact no-op result', function (): void {
        $version = new ComposerDryRunVersionParser()->parse(
            output: 'Nothing to install, update or remove',
            package: 'laravel/installer',
            installedFallback: static fn (): ?string => 'v5.16.0',
        );

        expect($version)->toBe('v5.16.0');
    });

    it('does not read the installed fallback when the dry run has a target operation', function (): void {
        $fallbackRead = false;

        $version = new ComposerDryRunVersionParser()->parse(
            output: '  - Installing laravel/installer (v5.17.0)',
            package: 'laravel/installer',
            installedFallback: static function () use (&$fallbackRead): ?string {
                $fallbackRead = true;

                return 'v5.16.0';
            },
        );

        expect($version)->toBe('v5.17.0');
        expect($fallbackRead)->toBeFalse();
    });

    it('fails closed on ambiguous or malformed dry-run output', function (string $output): void {
        expect(fn (): string => new ComposerDryRunVersionParser()->parse(
            output: $output,
            package: 'laravel/installer',
            installedFallback: static fn (): ?string => 'v5.16.0',
        ))
            ->toThrow(ToolManagerException::class);
    })->with([
        'wrong package' => ['  - Installing laravel/framework (v12.0.0)'],
        'duplicate target' => ["  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)"],
        'operation and no-op' => ["  - Installing laravel/installer (v5.17.0)\nNothing to install, update or remove"],
        'wrong package and no-op' => [
            "  - Installing laravel/framework (v12.0.0)\nNothing to install, update or remove",
        ],
        'malformed operation and no-op' => [
            "  - Installing laravel/installer v5.17.0\nNothing to install, update or remove",
        ],
        'missing operation' => ['Loading composer repositories with package information'],
        'malformed operation' => ['  - Installing laravel/installer v5.17.0'],
        'empty output' => [''],
        'control-bearing version' => ["  - Installing laravel/installer (v5.17.0\0hidden)"],
        'oversized version' => ['  - Installing laravel/installer ('.str_repeat('1', times: 256).')'],
        'oversized output' => [str_repeat('x', times: 32_769)],
    ]);

    it('fails closed when the no-op fallback is empty or malformed', function (?string $fallback): void {
        expect(fn (): string => new ComposerDryRunVersionParser()->parse(
            output: 'Nothing to install, update or remove',
            package: 'laravel/installer',
            installedFallback: static fn (): ?string => $fallback,
        ))
            ->toThrow(ToolManagerException::class);
    })->with([
        'absent' => [null],
        'empty' => [''],
        'control bearing' => ["v5.16.0\0hidden"],
        'oversized' => [str_repeat('1', times: 256)],
    ]);
});
