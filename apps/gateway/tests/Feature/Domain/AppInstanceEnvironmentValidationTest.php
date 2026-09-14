<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\Shared\ResourceOperationException;

it('accepts bounded keys values and the closed placeholder vocabulary', function (): void {
    app(AppInstanceEnvironmentValidator::class)->validate([
        '_EMPTY' => '',
        'HOST' => 'prefix-{{app_instance.domain}}',
        'ENVIRONMENT' => '{{app_instance.environment}}',
        'UNICODE' => 'hallo-wereld',
    ]);

    expect(true)->toBeTrue();
});

it('rejects invalid keys values and placeholder expressions', function (array $values, array $details): void {
    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($values))
        ->toThrow(function (ResourceOperationException $exception) use ($details): void {
            expect($exception->errorCode)
                ->toBe('env.configuration_invalid')
                ->and($exception->details)
                ->toBe($details);
        });
})->with([
    'empty key' => [['' => 'value'], ['rule' => 'key']],
    'non-ascii key' => [['KÉY' => 'value'], ['key' => 'KÉY', 'rule' => 'key']],
    'digit prefix' => [['1KEY' => 'value'], ['key' => '1KEY', 'rule' => 'key']],
    'key beyond 255 bytes' => [[str_repeat('K', 256) => 'value'], ['key' => str_repeat('K', 256), 'rule' => 'key']],
    'invalid utf8' => [['KEY' => "\xC3\x28"], ['key' => 'KEY', 'rule' => 'value']],
    'null byte' => [['KEY' => "before\0after"], ['key' => 'KEY', 'rule' => 'value']],
    'value beyond 65536 bytes' => [['KEY' => str_repeat('v', 65_537)], ['key' => 'KEY', 'rule' => 'value']],
    'unknown placeholder' => [['KEY' => '{{app_instance.url}}'], ['key' => 'KEY', 'rule' => 'placeholder', 'placeholder' => '{{app_instance.url}}']],
    'legacy placeholder' => [['KEY' => '{{instance.hostname}}'], ['key' => 'KEY', 'rule' => 'placeholder', 'placeholder' => '{{instance.hostname}}']],
    'retired hostname placeholder' => [['KEY' => '{{app_instance.hostname}}'], ['key' => 'KEY', 'rule' => 'placeholder', 'placeholder' => '{{app_instance.hostname}}']],
    'malformed placeholder' => [['KEY' => '{{app_instance.hostname}'], ['key' => 'KEY', 'rule' => 'placeholder']],
]);

it('rejects key-count and conservative generated-file limits', function (): void {
    $tooMany = [];
    for ($index = 0; $index < 1_025; $index++) {
        $tooMany["KEY_{$index}"] = '';
    }

    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($tooMany))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('env.configuration_invalid')
                ->and($exception->details)
                ->toBe(['rule' => 'key_count']);
        });

    $tooLarge = [];
    for ($index = 0; $index < 9; $index++) {
        $tooLarge["KEY_{$index}"] = str_repeat('\\', 65_536);
    }

    expect(fn () => app(AppInstanceEnvironmentValidator::class)->validate($tooLarge))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('env.configuration_invalid')
                ->and($exception->details['rule'])
                ->toBe('file_size')
                ->and($exception->details['key'] ?? null)
                ->toBeString()
                ->toStartWith('KEY_');
        });
});
