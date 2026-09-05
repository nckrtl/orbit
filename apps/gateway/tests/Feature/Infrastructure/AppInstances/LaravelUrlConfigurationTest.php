<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\ComposerSourceClassifier;

it('requires one Composer Laravel declaration and one regular Artisan marker', function (): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);
    $composer = json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR);

    expect($classifier->classify($composer, 'regular'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeTrue();
});

it('refuses partial conflicting and unsafe Laravel markers', function (array $composer, string $artisan): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode($composer, JSON_THROW_ON_ERROR), $artisan))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
})->with([
    'artisan only' => [['require' => ['php' => '^8.4']], 'regular'],
    'declaration only' => [['require' => ['laravel/framework' => '^13.0']], 'absent'],
    'duplicate declaration' => [
        [
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^13.0'],
        ],
        'regular',
    ],
    'symlinked artisan' => [['require' => ['laravel/framework' => '^13.0']], 'unsafe'],
]);

it('leaves a Composer non-Laravel source classified as PHP only', function (): void {
    $profile = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog)
        ->classify('{"require":{"php":"~8.4.0"}}', 'absent');

    expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeFalse();
});
