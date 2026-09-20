<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

trait SelectsAppDefinitionTarget
{
    protected function providedOption(string $name): bool
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '';
    }

    protected function providedProjectOption(): bool
    {
        return $this->providedOption('project') || $this->providedOption('app');
    }

    protected function appIdOption(string $errorCode = 'app.id_invalid'): int|false|null
    {
        $hasProject = $this->providedOption('project');
        $hasApp = $this->providedOption('app');

        if ($hasProject && $hasApp) {
            $this->renderGatewayFailure($errorCode, 'Use only one of --project or --app.');

            return false;
        }

        if (! $hasProject && ! $hasApp) {
            return null;
        }

        $id = filter_var(
            $hasProject ? $this->option('project') : $this->option('app'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($id)) {
            $this->renderGatewayFailure($errorCode, 'Project ID must be a positive integer.');

            return false;
        }

        return $id;
    }

    /** @return list<string>|false */
    protected function definitionEnvironments(string $errorCode): array|false
    {
        $value = $this->stringOption('for');

        if ($value === null) {
            $this->renderGatewayFailure($errorCode, 'The --for option is required with --project or --app.');

            return false;
        }

        $environments = [];

        foreach (explode(',', $value) as $part) {
            $environment = trim($part);

            if ($environment === '' || ! in_array($environment, ['development', 'production'], true)) {
                $this->renderGatewayFailure(
                    $errorCode,
                    'The --for option must list development and production at most once.',
                );

                return false;
            }

            $environments[] = $environment;
        }

        if (count($environments) !== count(array_unique($environments))) {
            $this->renderGatewayFailure(
                $errorCode,
                'The --for option must list development and production at most once.',
            );

            return false;
        }

        return array_values(array_unique($environments));
    }
}
