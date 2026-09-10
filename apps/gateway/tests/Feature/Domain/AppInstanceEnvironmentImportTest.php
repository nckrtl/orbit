<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\Shared\ResourceOperationException;

it('parses dotenv syntax and expands only preceding file-local values', function (): void {
    putenv('GATEWAY_FALLBACK=must-not-be-read');
    $_ENV['GATEWAY_FALLBACK'] = 'must-not-be-read';
    $contents = <<<'DOTENV'
        # comment
        BASE=alpha
        PLAIN=${BASE}-plain
        DOUBLE="line one\n${BASE}"
        SINGLE='literal ${BASE}'
        ESCAPED="quote: \""
        MULTILINE="first
        second"
        UNICODE=é
        UNICODE_EXPANDED="é${UNICODE}"

        DOTENV;

    $values = app(AppInstanceEnvironmentImporter::class)->parse($contents);

    expect($values)->toBe([
        'BASE' => 'alpha',
        'PLAIN' => 'alpha-plain',
        'DOUBLE' => "line one\nalpha",
        'SINGLE' => 'literal ${BASE}',
        'ESCAPED' => 'quote: "',
        'MULTILINE' => "first\nsecond",
        'UNICODE' => 'é',
        'UNICODE_EXPANDED' => 'éé',
    ]);
});

it('rejects duplicate unresolved invalid and oversized dotenv input atomically', function (string $contents): void {
    expect(fn () => app(AppInstanceEnvironmentImporter::class)->parse($contents))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.import_invalid');
        });
})->with([
    'duplicate' => ["KEY=one\nKEY=two\n"],
    'unresolved local expansion' => ['KEY=${MISSING}'],
    'gateway environment fallback' => ['KEY=${GATEWAY_FALLBACK}'],
    'invalid syntax' => ['KEY value'],
    'numeric key' => ['123=value'],
    'oversized' => [str_repeat('x', 1_048_577)],
]);
