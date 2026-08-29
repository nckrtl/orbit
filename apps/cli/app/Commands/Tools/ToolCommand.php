<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\GatewayCommand;
use Orbit\Sdk\Responses\Tools\ToolResponse;

abstract class ToolCommand extends GatewayCommand
{
    protected function nodeId(): ?int
    {
        $node = filter_var($this->option('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (is_int($node)) {
            return $node;
        }
        $this->renderGatewayFailure('tool.node_id_invalid', 'Node ID must be a positive integer.');

        return null;
    }

    protected function mayPrompt(): bool
    {
        return $this->input->isInteractive() && $this->option('json') !== true;
    }

    /** @param list<string> $choices */
    protected function chooseString(string $question, array $choices): ?string
    {
        $selected = $this->choice($question, $choices);

        return is_string($selected) && $selected !== '' ? $selected : null;
    }

    protected function promptedStringArgument(
        string $argument,
        string $question,
        string $errorCode,
        string $errorMessage,
    ): ?string {
        $value = $this->argument($argument);

        if ($value === null && $this->mayPrompt()) {
            /** @mago-expect analysis:mixed-assignment Console prompts cross an untyped framework boundary. */
            $value = $this->ask($question);
        }

        if (! is_string($value) || $value === '') {
            $this->renderGatewayFailure($errorCode, $errorMessage);

            return null;
        }

        return $value;
    }

    protected function toolId(): ?int
    {
        return $this->positiveId('tool', 'Tool', 'tool.id_invalid');
    }

    protected function writeToolJson(ToolResponse $response): void
    {
        $this->writeJson($response->toArray());
    }

    protected function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string) $value;
    }
}
