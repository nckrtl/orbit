<?php

declare(strict_types=1);

use App\Support\LocalDiagnosticRedactor;

it('redacts local diagnostics with the process log credential matrix', function (): void {
    expect(class_exists(LocalDiagnosticRedactor::class))->toBeTrue();

    $secret = implode('-', ['local', 'diagnostic', 'secret']);
    $diagnostics = implode("\n", [
        'APP_KEY='.$secret,
        'Authorization: Bearer '.$secret,
        'https://orbit:'.$secret.'@gateway.example.test/path',
        'https://gateway.example.test/path?token='.$secret,
        '-----BEGIN PRIVATE KEY-----',
        $secret,
        '-----END PRIVATE KEY-----',
    ]);

    $redacted = new LocalDiagnosticRedactor()->redact($diagnostics);

    expect($redacted)
        ->not
        ->toContain($secret)
        ->toContain('APP_KEY=[redacted]')
        ->toContain('Authorization: [redacted]')
        ->toContain('https://[redacted]@gateway.example.test/path')
        ->toContain('token=[redacted]');
});

it('returns a bounded redacted tail across diagnostic chunks', function (): void {
    expect(class_exists(LocalDiagnosticRedactor::class))->toBeTrue();

    $secret = implode('-', ['chunked', 'local', 'secret']);
    $redacted = new LocalDiagnosticRedactor()->redactedTail([
        str_repeat('x', 40_000).'\nAUTHORIZ',
        'ATION: Bearer '.$secret."\nlast line",
    ]);

    expect(strlen($redacted))
        ->toBeLessThanOrEqual(32_768)
        ->and($redacted)
        ->not
        ->toContain($secret)
        ->toEndWith('last line');
});
