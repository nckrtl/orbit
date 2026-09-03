<?php

declare(strict_types=1);

use Tests\Support\TemporaryPaths;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Scenario');

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

/** One pinned attempt identity so resource names stay deterministic across a test. */
function attemptId(string $character = 'a'): App\E2E\Value\AttemptId
{
    return new App\E2E\Value\AttemptId(str_repeat($character, 32));
}

function featureTarget(string $issue, string $character = 'a'): App\E2E\Value\TopologyTarget
{
    return App\E2E\Value\TopologyTarget::feature($issue, attemptId($character));
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
