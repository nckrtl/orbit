<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\Shared\ResourceOperationException;

it('accepts bounded keys values and the closed placeholder vocabulary', function (): void {
    app(AppInstanceEnvironmentValidator::class)->validate([
        '_EMPTY' => '',
        'HOST' => 'prefix-{{app_instance.hostname}}',
        'ENVIRONMENT' => '{{app_instance.environment}}',
        'UNICODE' => 'hallo-wereld',
    ]);

    expect(true)->toBeTrue();
});

it('rejects invalid keys values and placeholder expressions', function (array $values): void {
    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($values))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.configuration_invalid');
        });
})->with([
    'empty key' => [['' => 'value']],
    'non-ascii key' => [['KÉY' => 'value']],
    'digit prefix' => [['1KEY' => 'value']],
    'key beyond 255 bytes' => [[str_repeat('K', 256) => 'value']],
    'invalid utf8' => [['KEY' => "\xC3\x28"]],
    'null byte' => [['KEY' => "before\0after"]],
    'value beyond 65536 bytes' => [['KEY' => str_repeat('v', 65_537)]],
    'unknown placeholder' => [['KEY' => '{{app_instance.url}}']],
    'legacy placeholder' => [['KEY' => '{{instance.hostname}}']],
    'malformed placeholder' => [['KEY' => '{{app_instance.hostname}']],
]);

it('rejects key-count and conservative generated-file limits', function (): void {
    $tooMany = [];
    for ($index = 0; $index < 1_025; $index++) {
        $tooMany["KEY_{$index}"] = '';
    }

    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($tooMany))
        ->toThrow(ResourceOperationException::class);

    $tooLarge = [];
    for ($index = 0; $index < 9; $index++) {
        $tooLarge["KEY_{$index}"] = str_repeat('\\', 65_536);
    }

    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($tooLarge))
        ->toThrow(ResourceOperationException::class);
});
