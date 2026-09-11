<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\ScenarioAggregate;
use App\E2E\Value\ScenarioRunId;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ScenarioSuiteRunner
{
    public function __construct(
        private ScenarioCatalog $catalog,
        private ScenarioRunStore $runs,
        private ScenarioPestProcess $process,
        private ScenarioRecovery $recovery,
        private SecretRedactor $redactor,
    ) {}

    /** @param list<string> $selected @param (Closure(string): void)|null $output */
    public function run(
        string $candidate,
        string $repository,
        string $primary,
        string $lane,
        array $selected = [],
        ?Closure $output = null,
    ): ScenarioAggregate {
        return $this->runSelected($candidate, $repository, $primary, $lane, $selected, 1, $output);
    }

    /** @param list<string> $selected @param (Closure(string): void)|null $output */
    public function runAll(
        string $candidate,
        string $repository,
        string $primary,
        array $selected,
        int $workers,
        ?Closure $output = null,
    ): ScenarioAggregate {
        return $this->runSelected($candidate, $repository, $primary, null, $selected, $workers, $output);
    }

    /** @param list<string> $selected @param (Closure(string): void)|null $output */
    private function runSelected(
        string $candidate,
        string $repository,
        string $primary,
        ?string $lane,
        array $selected,
        int $workers,
        ?Closure $output,
    ): ScenarioAggregate {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new InvalidArgumentException('The exact scenario candidate is invalid.');
        }
        if (! str_starts_with($repository, '/') || ! str_starts_with($primary, '/')) {
            throw new InvalidArgumentException('Scenario repository roots must be absolute.');
        }
        if ($workers < 1) {
            throw new InvalidArgumentException('The scenario worker count must be a positive integer.');
        }

        // Resolve and validate the complete catalog and requested selection before
        // creating run state or allowing a child process to reach Incus.
        $definitions = $this->catalog->select($candidate, $selected, $lane);
        $run = ScenarioRunId::generate();
        $startedAt = self::now();
        $this->runs->beginRun($run, $candidate, $definitions, $startedAt);
        $scheduler = new ScenarioScheduler($this->runs, $this->process, $this->recovery, $this->redactor);
        $results = $scheduler->run(
            $definitions,
            $candidate,
            $run,
            $repository,
            $primary,
            $workers,
            $output,
        );

        $aggregate = new ScenarioAggregate($candidate, $run, $results, $startedAt, self::now());
        $this->runs->writeAggregate($aggregate);

        return $aggregate;
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
