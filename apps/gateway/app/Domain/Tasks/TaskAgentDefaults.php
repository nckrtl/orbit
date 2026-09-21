<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskAgentDefaults
{
    public const string ImplementerInstanceId = 'codex';

    public const string ImplementerModel = 'gpt-5.6-luna';

    public const string ImplementerOptionId = 'reasoningEffort';

    public const string ImplementerEffort = 'low';

    public const string ReviewerInstanceId = 'claudeAgent';

    public const string ReviewerModel = 'claude-opus-5';

    public const string ReviewerOptionId = 'effort';

    public const string ReviewerEffort = 'high';

    /**
     * @return array{instanceId: string, model: string, options: list<array{id: string, value: string}>}
     */
    public static function implementerSelection(?string $model = null): array
    {
        return [
            'instanceId' => self::ImplementerInstanceId,
            'model' => is_string($model) && $model !== '' ? $model : self::ImplementerModel,
            'options' => [
                ['id' => self::ImplementerOptionId, 'value' => self::ImplementerEffort],
            ],
        ];
    }

    /**
     * @return array{instanceId: string, model: string, options: list<array{id: string, value: string}>}
     */
    public static function reviewerSelection(?string $model = null): array
    {
        return [
            'instanceId' => self::ReviewerInstanceId,
            'model' => is_string($model) && $model !== '' ? $model : self::ReviewerModel,
            'options' => [
                ['id' => self::ReviewerOptionId, 'value' => self::ReviewerEffort],
            ],
        ];
    }
}
