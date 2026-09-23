<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;

/**
 * Maps an Orbit model name to Pi's `provider/model` form. A name that already names a provider
 * passes through. With a configured provider, such as a CLIProxyAPI endpoint, every plain name
 * uses it. Otherwise the name selects Pi's built-in subscription provider.
 *
 * Claude models are refused: Anthropic permits subscription credentials only in its own
 * applications, including when a proxy relays them, so they run on T3.
 */
final readonly class PiModel
{
    public static function forModel(string $model, ?string $provider = null): string
    {
        [$named, $name] = str_contains($model, '/') ? explode('/', $model, 2) : [null, $model];
        if ($named === 'anthropic' || str_starts_with($name, 'claude')) {
            throw new AgentDriverException('Claude models run on the T3 driver, not on Pi.');
        }
        if ($named !== null) {
            return $model;
        }
        if ($provider !== null && $provider !== '') {
            return $provider.'/'.$model;
        }

        return match (true) {
            str_starts_with($model, 'gpt-'), preg_match('/^o\d/', $model) === 1 => 'openai-codex/'.$model,
            str_starts_with($model, 'grok-') => 'xai/'.$model,
            default => throw new AgentDriverException('Name the Pi provider for model '.$model.' as provider/model.'),
        };
    }
}
