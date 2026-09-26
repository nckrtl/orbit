<?php

declare(strict_types=1);

use App\Infrastructure\Activity\CommandActivityInputSanitizer;

describe('the redaction cases shared with the Node agent', function (): void {
    it('redact every case exactly as the Node agent does', function (): void {
        $path = dirname(base_path()).'/agent/tests/redaction_cases.json';

        expect(is_file($path))->toBeTrue();

        /** @var list<array{name: string, input: string, expected: string}> $cases */
        $cases = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        $sanitizer = app(CommandActivityInputSanitizer::class);

        expect($cases)->not->toBeEmpty();

        foreach ($cases as $case) {
            expect($sanitizer->redactText($case['input']))->toBe($case['expected'], $case['name']);
        }
    });
});
