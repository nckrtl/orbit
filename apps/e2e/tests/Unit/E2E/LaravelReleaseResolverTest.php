<?php

declare(strict_types=1);

use App\E2E\LaravelReleaseResolver;
use App\E2E\Value\LaravelRelease;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract. */
    Facade::setFacadeApplication($container);
});

describe('LaravelReleaseResolver', function (): void {
    it('rejects invalid release identities at construction', function (): void {
        expect(fn () => new LaravelRelease('v13.0', str_repeat('a', 40)))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => new LaravelRelease('v13.0.0', str_repeat('A', 40)))
            ->toThrow(InvalidArgumentException::class);
    });
    it('selects the newest stable application tag and its exact commit', function (): void {
        Process::fake([
            '*' => Process::result(output: implode("\n", [
                str_repeat('a', 40)."\trefs/tags/v13.1.0",
                str_repeat('2', 40)."\trefs/tags/v13.2.0-beta.1",
                str_repeat('c', 40)."\trefs/tags/v13.1.2",
                str_repeat('d', 40)."\trefs/tags/v14.0.0",
            ])
                ."\n"),
        ]);

        $release = new LaravelReleaseResolver()->resolve('>=13.0.0');

        expect($release->tag)->toBe('v14.0.0')->and($release->commit)->toBe(str_repeat('d', 40));

        Process::assertRan(
            fn (PendingProcess $process): bool => (
                $process->command === [
                    'git',
                    'ls-remote',
                    '--tags',
                    '--refs',
                    'https://github.com/laravel/laravel.git',
                ]
            ),
        );
    });

    it('rejects malformed, moved, missing, prerelease-only, and noncommit tags', function (
        string $output,
        string $constraint,
    ): void {
        Process::fake(['*' => Process::result(output: $output)]);

        expect(fn () => new LaravelReleaseResolver()->resolve($constraint))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'malformed output' => ["not-a-ref\n", '^13.0'],
        'moved tag' => [
            str_repeat('1', 40)."\trefs/tags/v13.0.0\n".str_repeat('2', 40)."\trefs/tags/v13.0.0\n",
            '^13.0',
        ],
        'missing release' => [str_repeat('1', 40)."\trefs/tags/v12.0.0\n", '^13.0'],
        'prerelease only' => [str_repeat('1', 40)."\trefs/tags/v13.0.0-rc.1\n", '^13.0'],
        'noncommit tag' => [str_repeat('1', 40)."\trefs/tags/not-a-release\n", '^13.0'],
    ]);
});
