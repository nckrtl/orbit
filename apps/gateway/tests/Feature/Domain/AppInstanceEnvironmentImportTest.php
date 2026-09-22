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

it('refuses incomplete quoted input without accepting earlier complete entries', function (string $contents): void {
    expect(fn () => app(AppInstanceEnvironmentImporter::class)->parse($contents))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.import_invalid')
                ->and($exception->getMessage())->toBe('The AppInstance environment file is invalid.')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details)->toBeEmpty();
        });
})->with([
    'double quote without final newline' => ['SOURCE_ONLY="environment-secret-sentinel'],
    'double quote with LF' => ["SOURCE_ONLY=\"environment-secret-sentinel\n"],
    'double quote with CRLF' => ["SOURCE_ONLY=\"environment-secret-sentinel\r\n"],
    'double quote with CR' => ["SOURCE_ONLY=\"environment-secret-sentinel\r"],
    'valid prefix with LF' => ["BEFORE=valid\nSOURCE_ONLY=\"environment-secret-sentinel\n"],
    'valid prefix with CRLF' => ["BEFORE=valid\r\nSOURCE_ONLY=\"environment-secret-sentinel\r\n"],
    'valid prefix with CR' => ["BEFORE=valid\rSOURCE_ONLY=\"environment-secret-sentinel\r"],
    'escaped quote remains open' => ['SOURCE_ONLY="environment-secret-sentinel\"still-open'],
    'escaped backslash remains open' => ['SOURCE_ONLY="environment-secret-sentinel\\\\'],
    'comments and blanks inside unfinished multiline value' => ["SOURCE_ONLY=\"environment-secret-sentinel\n# comment\n\n"],
    'spoofed completion key before unfinished value' => ["__ORBIT_IMPORT_COMPLETE__=1\nSOURCE_ONLY=\"environment-secret-sentinel"],
    'single quote without final newline' => ["SOURCE_ONLY='environment-secret-sentinel"],
    'single quote with final newline' => ["SOURCE_ONLY='environment-secret-sentinel\n"],
]);

it('preserves completed multiline escaping comments and expansion across supported line endings', function (
    string $lineEnding,
    bool $finalNewline,
): void {
    $contents = <<<'DOTENV'
        # An unmatched " in a comment is not an unfinished value.
        BASE=alpha
        SINGLE='literal ${BASE} "quote'
        QUOTE="escaped \" quote"
        BACKSLASH="C:\\path\\file"
        MULTILINE="first
        second # literal"
        EXPANDED=${BASE}-tail
        __ORBIT_IMPORT_COMPLETE__=caller-value

        DOTENV;
    $contents = str_replace("\n", $lineEnding, $contents);
    $contents = rtrim($contents, "\r\n").($finalNewline ? $lineEnding : '');

    expect(app(AppInstanceEnvironmentImporter::class)->parse($contents))->toBe([
        'BASE' => 'alpha',
        'SINGLE' => 'literal ${BASE} "quote',
        'QUOTE' => 'escaped " quote',
        'BACKSLASH' => 'C:\path\file',
        'MULTILINE' => "first\nsecond # literal",
        'EXPANDED' => 'alpha-tail',
        '__ORBIT_IMPORT_COMPLETE__' => 'caller-value',
    ]);
})->with([
    'LF with final newline' => ["\n", true],
    'LF without final newline' => ["\n", false],
    'CRLF with final newline' => ["\r\n", true],
    'CRLF without final newline' => ["\r\n", false],
    'CR with final newline' => ["\r", true],
    'CR without final newline' => ["\r", false],
]);

it('keeps empty and comment-only files empty without exposing the completeness probe', function (string $contents): void {
    expect(app(AppInstanceEnvironmentImporter::class)->parse($contents))->toBe([]);
})->with([
    'empty' => '',
    'blank LF' => "\n \t\n",
    'blank CRLF' => "\r\n \t\r\n",
    'blank CR' => "\r \t\r",
    'comment with quote' => "# comment=\"unfinished\n",
]);
