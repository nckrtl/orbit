<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
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
function attemptId(string $character = 'a'): AttemptId
{
    return new AttemptId(str_repeat($character, 32));
}

function featureTarget(
    string $issue,
    string $character = 'a',
    ?TopologyRecipe $recipe = null,
): TopologyTarget {
    return TopologyTarget::feature($issue, attemptId($character), $recipe);
}

function topologyConstructionFixture(
    string $issue = 'AUX-99',
    string $character = 'a',
): TopologyConstructionInputs {
    $target = featureTarget($issue, $character);

    return TopologyConstructionInputs::forGeneration($target, 'fixture-generation', 2);
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
