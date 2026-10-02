<?php

declare(strict_types=1);

namespace App\Commands\Projects\Concerns;

use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepsResponse;

trait RendersDevelopmentDeploySteps
{
    /** @return bool|'invalid'|null */
    protected function developmentRequired(mixed $value): bool|string|null
    {
        return match ($value) {
            null => null,
            'true' => true,
            'false' => false,
            default => 'invalid',
        };
    }

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

    private function writeDevelopmentStepLine(string $text): void
    {
        ConsoleWriter::write($this->output, TerminalText::safe($text)."\n");
    }

    protected function renderDevelopmentDeployStep(DevelopmentDeployStepResponse $step): int
    {
        if ($this->option('json') === true) {
            $this->writeJson([...$step->toArray(), 'request_id' => $step->requestId]);

            return self::SUCCESS;
        }

        $this->writeDevelopmentStepLine('Name: '.$step->name);
        $this->writeDevelopmentStepLine('Command: '.$step->command);
        $this->writeDevelopmentStepLine('Timeout: '.$step->timeoutSeconds.' seconds');
        $this->writeDevelopmentStepLine('Required: '.($step->required ? 'true' : 'false'));

        return self::SUCCESS;
    }

    protected function renderDevelopmentDeploySteps(DevelopmentDeployStepsResponse $response): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        if ($response->steps === []) {
            $this->writeDevelopmentStepLine('No steps.');

            return self::SUCCESS;
        }

        foreach ($response->steps as $index => $step) {
            $this->writeDevelopmentStepLine(($index + 1).'. '.$step->name.' ('.$step->timeoutSeconds.'s)');
            $this->writeDevelopmentStepLine('   '.$step->command);
            $this->writeDevelopmentStepLine('   Required: '.($step->required ? 'true' : 'false'));
        }

        return self::SUCCESS;
    }
}
