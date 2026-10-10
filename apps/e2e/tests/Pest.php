<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\SnapshotScenarioRunner;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Tests\Support\GuestScripts;
use Tests\Support\TemporaryPaths;
use Tests\TestCase;

GuestScripts::useGnuUserland();

// Fixture commits take their identity from the environment. `git config user.*` in a linked worktree writes the
// shared configuration of its repository, and an inherited GIT_DIR would point `git -C <fixture>` at the checkout
// that runs the suite, so fixtures never configure an identity and inherit no repository.
foreach ([
    'GIT_AUTHOR_NAME' => 'Orbit Developer',
    'GIT_AUTHOR_EMAIL' => 'developer@example.com',
    'GIT_COMMITTER_NAME' => 'Orbit Developer',
    'GIT_COMMITTER_EMAIL' => 'developer@example.com',
    'GIT_DIR' => null,
    'GIT_WORK_TREE' => null,
    'GIT_COMMON_DIR' => null,
    'GIT_INDEX_FILE' => null,
    'GIT_OBJECT_DIRECTORY' => null,
    'GIT_ALTERNATE_OBJECT_DIRECTORIES' => null,
] as $variable => $value) {
    if ($value === null) {
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);

        continue;
    }

    putenv("{$variable}={$value}");
    $_ENV[$variable] = $_SERVER[$variable] = $value;
}

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->beforeEach(function (): void {
    $primary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');
    if (! is_string($primary) || ! str_starts_with($primary, '/')) {
        throw new RuntimeException('Run scenario tests through bin/e2e-scenarios.');
    }

    $this->app->instance(StatePaths::class, StatePaths::forPrimary($primary));
    foreach ([
        AtomicJsonStore::class,
        ScenarioRunStore::class,
        ColdTopologyConstructor::class,
        SnapshotScenarioRunner::class,
    ] as $service) {
        $this->app->forgetInstance($service);
    }
})->in('Scenario');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

pest()->tia()->directory(is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : dirname(__DIR__).'/.orbit-tia');

$scenarioTiaDirectory = getenv('ORBIT_SCENARIO_TIA_DIRECTORY');
$scenarioPrimary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');

if (is_string($scenarioTiaDirectory) && is_string($scenarioPrimary)) {
    $scenarioTiaRoot = rtrim($scenarioPrimary, '/').'/.e2e/scenarios/runs/';

    if (! str_starts_with($scenarioTiaDirectory, $scenarioTiaRoot)) {
        throw new RuntimeException('The scenario TIA directory is invalid.');
    }

    pest()->tia()->directory($scenarioTiaDirectory);
}

/** Serialized with the pre-operator value classes from commit 9f1e79c70. */
function preOperatorTopologyRecord(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/Fixtures/topology/pre-operator-schema-2.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

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

function temporaryDirectory(): string
{
    return TemporaryPaths::directory();
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

function guestScriptSource(string $name): string
{
    return GuestScripts::source($name);
}

function guestScriptPath(string $name): string
{
    return GuestScripts::path($name);
}
