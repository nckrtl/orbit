<?php

declare(strict_types=1);

namespace App\Commands\Instances\Concerns;

use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Orbit\Sdk\Responses\Instances\LifecycleStepsResponse;

trait RendersLifecycleSteps
{
    protected function projectId(): ?int
    {
        $id = filter_var(
            $this->option('project'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($id)) {
            $this->renderGatewayFailure('project.id_invalid', 'Project ID must be a positive integer.');

            return null;
        }

        return $id;
    }

    protected function lifecycleName(): ?string
    {
        return $this->stringArgument('name', 'Step name', 'lifecycle_step.name_required');
    }

    protected function lifecycleTimeout(mixed $timeout): int|false|null
    {
        if ($timeout === null) {
            return null;
        }

        $timeoutSeconds = filter_var($timeout, FILTER_VALIDATE_INT);

        return is_int($timeoutSeconds) ? $timeoutSeconds : false;
    }

    /**
     * Parses repeated `--rebalance=NAME=SECONDS` values. Null when none were given, false when one is malformed.
     *
     * @return list<array{name: string, timeout_seconds: int}>|false|null
     */
    protected function lifecycleRebalance(mixed $values): array|false|null
    {
        if (! is_array($values) || $values === []) {
            return null;
        }

        $rebalance = [];

        foreach ($values as $value) {
            [$name, $seconds] = array_pad(explode('=', is_string($value) ? $value : '', 2), 2, '');
            $timeout = filter_var($seconds, FILTER_VALIDATE_INT);

            if ($name === '' || ! is_int($timeout)) {
                return false;
            }

            $rebalance[] = ['name' => $name, 'timeout_seconds' => $timeout];
        }

        return $rebalance;
    }

    protected function renderLifecycleStep(LifecycleStepResponse $step): int
    {
        if ($this->option('json') === true) {
            $this->writeJson([...$step->toArray(), 'request_id' => $step->requestId]);

            return self::SUCCESS;
        }

        $this->line('Name: '.$step->name);
        $this->line('Command: '.$step->command);
        $this->line('Timeout: '.$step->timeoutSeconds.' seconds');

        return self::SUCCESS;
    }

    protected function renderLifecycleSteps(LifecycleStepsResponse $response): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        if ($response->steps === []) {
            $this->line('No steps.');

            return self::SUCCESS;
        }

        foreach ($response->steps as $index => $step) {
            $this->line(($index + 1).'. '.$step->name.' ('.$step->timeoutSeconds.'s)');
            $this->line('   '.$step->command);
        }

        return self::SUCCESS;
    }
}
