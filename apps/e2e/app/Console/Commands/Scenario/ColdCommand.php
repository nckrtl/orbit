<?php

declare(strict_types=1);

namespace App\Console\Commands\Scenario;

use App\E2E\ScenarioSuiteRunner;
use Illuminate\Console\Command;
use Throwable;

final class ColdCommand extends Command
{
    #[\Override]
    protected $signature = 'scenario:cold {--scenario=* : Select one committed scenario ID; repeat to select more} {--json}';

    #[\Override]
    protected $description = 'Run selected committed cold scenarios serially for one exact candidate';

    public function handle(ScenarioSuiteRunner $runner): int
    {
        try {
            $candidate = $this->environment('ORBIT_SCENARIO_CANDIDATE_SHA');
            $repository = $this->environment('ORBIT_SCENARIO_REPOSITORY');
            $primary = $this->environment('ORBIT_SCENARIO_PRIMARY_ROOT');
            $selected = $this->option('scenario');
            if (! array_all($selected, static fn (?string $id): bool => is_string($id))) {
                throw new \InvalidArgumentException('The scenario selection is invalid.');
            }
            $selected = array_values($selected);
            $aggregate = $runner->run(
                $candidate,
                $repository,
                $primary,
                'cold',
                $selected,
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

    private function environment(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Scenario environment [{$name}] is absent.");
        }

        return $value;
    }
}
