<?php

declare(strict_types=1);

use Tests\Support\TemporaryPaths;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

/** @return array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string} */
function verificationProbeFixture(bool $passed = true, string $probe = 'fixture'): array
{
    return [
        'passed' => $passed,
        'checked_at' => '2026-08-29T12:34:56+00:00',
        'expected' => 'healthy',
        'observed' => $passed ? 'healthy' : 'failed',
        'evidence_ref' => 'incus://orbit-e2e-fixture/'.$probe,
    ];
}

function temporaryPath(string $prefix, int $randomBytes = 8): string
{
    return TemporaryPaths::path($prefix, $randomBytes);
}

function temporaryFile(string $prefix): string
{
    return TemporaryPaths::file($prefix);
}

pest()->afterEach(fn () => TemporaryPaths::cleanup());
