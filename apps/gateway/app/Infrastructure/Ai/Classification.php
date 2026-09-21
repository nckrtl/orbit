<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use Closure;

final class Classification
{
    /** @var array<string, ChoiceAnswer|array<string, mixed>>|null */
    private static ?array $fakeAnswers = null;

    /**
     * @param  string|array<string, mixed>  $state
     */
    public static function of(string|array $state): PendingClassification
    {
        return new PendingClassification($state);
    }

    /**
     * @param  array<string, ChoiceAnswer|array<string, mixed>>|Closure(string|array<string, mixed>): array<string, ChoiceAnswer|array<string, mixed>>  $responses
     */
    public static function fake(array|Closure $responses = []): void
    {
        self::$fakeAnswers = is_array($responses) ? $responses : [];
        PendingClassification::fakeUsing($responses);
    }

    public static function isFaked(): bool
    {
        return self::$fakeAnswers !== null || PendingClassification::isFaked();
    }

    public static function resetFake(): void
    {
        self::$fakeAnswers = null;
        PendingClassification::fakeUsing(null);
    }
}
