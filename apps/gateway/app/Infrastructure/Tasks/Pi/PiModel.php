<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;

/**
 * Maps an Orbit model name to Pi's `provider/model` form. A name that already names a provider
 * passes through. Claude models are refused: Anthropic permits subscription logins only in its
 * own applications, so they run on T3.
 */
final readonly class PiModel
{
    public static function forModel(string $model): string
    {
        if (str_contains($model, '/')) {
            [$provider] = explode('/', $model, 2);
            if ($provider === 'anthropic') {
                throw new AgentDriverException('Claude models run on the T3 driver, not on Pi.');
            }

            return $model;
        }

        return match (true) {
            str_starts_with($model, 'claude') => throw new AgentDriverException('Claude models run on the T3 driver, not on Pi.'),
            str_starts_with($model, 'gpt-'), preg_match('/^o\d/', $model) === 1 => 'openai-codex/'.$model,
            str_starts_with($model, 'grok-') => 'xai/'.$model,
            default => throw new AgentDriverException('Name the Pi provider for model '.$model.' as provider/model.'),
        };
    }
}
