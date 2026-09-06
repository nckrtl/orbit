<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\ComposerSourceClassifier;

it('owns a finite descending AppInstance PHP candidate catalog', function (): void {
    expect(new AppInstancePhpVersionCatalog()->versions())->toBe(['8.5', '8.4']);
});

it('selects the highest compatible candidate', function (?string $constraint, string $version): void {
    expect(new AppInstancePhpVersionCatalog()->select($constraint))->toBe($version);
})->with([
    'no constraint' => [null, '8.5'],
    'both candidates' => ['^8.4', '8.5'],
    '8.4 only' => ['~8.4.0', '8.4'],
    'bounded above' => ['>=8.4 <8.5', '8.4'],
]);

it('refuses invalid and unsupported source constraints', function (string $constraint): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode(['require' => [
        'php' => $constraint,
    ]], JSON_THROW_ON_ERROR), 'absent'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('source-classification')
                ->and($exception->errorCode)
                ->toBe('app-dev.php_version_unsupported');
        });
})->with([
    'invalid' => ['not a constraint'],
    'below catalog' => ['<8.4'],
    'between candidates' => ['>8.4 <8.5'],
    'above catalog' => ['>8.5'],
]);

it('classifies Composer metadata as PHP and metadata absence as non-PHP', function (): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect($classifier->classify('{"name":"acme/site"}', 'absent'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeFalse();
});
