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
 * applications, including when a proxy relays them. They are unavailable for task agents.
 */
final readonly class PiModel
{
    public static function forSandbox(string $model): string
    {
        $qualified = self::forModel($model, 'orbit-sandbox');
        $name = substr($qualified, strpos($qualified, '/') + 1);

        return 'orbit-sandbox/'.$name;
    }

    public static function forModel(string $model, ?string $provider = null): string
    {
        $slash = strpos($model, '/');
        $named = $slash === false ? null : substr($model, 0, $slash);
        $name = $slash === false ? $model : substr($model, $slash + 1);
        if ($named === 'anthropic' || str_starts_with($name, 'claude')) {
            throw new AgentDriverException('Claude models are unavailable for task agents.');
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
