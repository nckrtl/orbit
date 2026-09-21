<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Builds a T3 `modelSelection` from an Orbit task model slug.
 *
 * T3 routes a thread by `instanceId`, the configured provider instance, and
 * never by the model slug. Each provider also names its reasoning option
 * differently: Codex reads `reasoningEffort` and ignores a plain `effort`,
 * which silently leaves the model on its medium default.
 */
final readonly class T3ModelCatalog
{
    public const string CodexInstance = 'codex';

    public const string ClaudeInstance = 'claudeAgent';

    /**
     * Slugs Orbit stored before the catalog was verified, mapped onto the
     * catalog entry they meant. Groups created back then still carry them.
     *
     * @var array<string, string>
     */
    private const RetiredSlugs = [
        'codex-luna-lite' => 'gpt-5.6-luna',
        'claude-opus' => 'claude-opus-5',
    ];

    /** @var array<string, string> */
    private const EffortOptions = [
        self::CodexInstance => 'reasoningEffort',
        self::ClaudeInstance => 'effort',
    ];

    /**
     * @return array{instanceId: string, model: string, options: list<array{id: string, value: string}>}
     */
    public static function selection(string $model, string $effort): array
    {
        $slug = self::RetiredSlugs[$model] ?? $model;
        $instanceId = self::instanceFor($slug);

        return [
            'instanceId' => $instanceId,
            'model' => $slug,
            'options' => [
                ['id' => self::EffortOptions[$instanceId], 'value' => $effort],
            ],
        ];
    }

    /**
     * Claude models run on the `claudeAgent` instance. Every other catalog
     * model Orbit dispatches is a Codex model.
     */
    private static function instanceFor(string $slug): string
    {
        return str_starts_with($slug, 'claude-') ? self::ClaudeInstance : self::CodexInstance;
    }
}
