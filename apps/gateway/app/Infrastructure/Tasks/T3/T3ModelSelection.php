<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

final readonly class T3ModelSelection
{
    /** @return array{instanceId: string, model: string, options: list<array{id: string, value: string}>} */
    public static function forModel(string $model, string $effort): array
    {
        $claude = str_starts_with($model, 'claude');

        return [
            'instanceId' => $claude ? 'claudeAgent' : 'codex',
            'model' => $model,
            'options' => [['id' => $claude ? 'effort' : 'reasoningEffort', 'value' => $effort]],
        ];
    }
}
