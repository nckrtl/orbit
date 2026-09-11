<?php

declare(strict_types=1);

namespace App\Console\Commands\Scenario;

use App\E2E\ScenarioSuiteRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class RunCommand extends Command
{
    #[\Override]
    protected $signature = 'scenario:run {--workers= : Positive maximum number of active workers} {--scenario=* : Select one committed scenario ID; repeat to select more} {--json}';

    #[\Override]
    protected $description = 'Run selected committed cold and snapshot scenarios with bounded concurrency';

    public function handle(ScenarioSuiteRunner $runner): int
    {
        try {
            $candidate = $this->environment('ORBIT_SCENARIO_CANDIDATE_SHA');
            $repository = $this->environment('ORBIT_SCENARIO_REPOSITORY');
            $primary = $this->environment('ORBIT_SCENARIO_PRIMARY_ROOT');
            $selected = $this->option('scenario');
            if (! array_all($selected, static fn (?string $id): bool => is_string($id))) {
                throw new InvalidArgumentException('The scenario selection is invalid.');
            }
            $workers = $this->workerCount($this->option('workers'));
            $aggregate = $runner->runAll(
                $candidate,
                $repository,
                $primary,
                array_values($selected),
                $workers,
                fn (string $output) => $this->output->write($output),
            );
            $payload = $aggregate->toArray();
            $this->line($this->option('json')
                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : "scenario run {$aggregate->run->value}: ".($aggregate->successful() ? 'passed' : 'failed'));

            return $aggregate->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function workerCount(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The scenario worker count must be a positive integer.');
        }
        $workers = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);
        if (! is_int($workers)) {
            throw new InvalidArgumentException('The scenario worker count must be a positive integer.');
        }

        return $workers;
    }

    private function environment(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Scenario environment [{$name}] is absent.");
        }

        return $value;
    }
}
