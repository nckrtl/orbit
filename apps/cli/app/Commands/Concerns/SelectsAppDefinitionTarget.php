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

    protected function appIdOption(string $errorCode = 'app.id_invalid'): int|false|null
    {
        if (! $this->providedOption('app')) {
            return null;
        }

        $id = filter_var($this->option('app'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure($errorCode, 'App ID must be a positive integer.');

            return false;
        }

        return $id;
    }

    /** @return list<string>|false|null */
    protected function definitionEnvironments(bool $required, string $errorCode): array|false|null
    {
        $value = $this->stringOption('for');

        if ($value === null) {
            if ($required) {
                $this->renderGatewayFailure($errorCode, 'The --for option is required with --app.');

                return false;
            }

            return null;
        }

        if (! $required) {
            $this->renderGatewayFailure($errorCode, 'The --for option requires --app.');

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
