<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stores the kind and question of an assistance request.
 *
 * A failure never replaces an open direction request. A direction request replaces an open failure.
 * An open direction request changes only when the caller replaces it on purpose, such as an operator comment.
 */
final class TaskAssistance
{
    public const string ImplementerBlockedPrefix = 'The implementer is blocked: ';

    public const string ReviewerBlockedPrefix = 'The reviewer is blocked: ';

    /**
     * @return array{assistance_requested: true, assistance_kind: AssistanceKind, assistance_question: string|null, assistance_reason: string}
     */
    public static function attributes(AssistanceKind $kind, ?string $question, string $reason): array
    {
        return [
            'assistance_requested' => true,
            'assistance_kind' => $kind,
            'assistance_question' => $kind === AssistanceKind::Direction ? $question : null,
            'assistance_reason' => $reason,
        ];
    }

    /**
     * @return array{assistance_requested: false, assistance_kind: null, assistance_question: null, assistance_reason: null}
     */
    public static function cleared(): array
    {
        return [
            'assistance_requested' => false,
            'assistance_kind' => null,
            'assistance_question' => null,
            'assistance_reason' => null,
        ];
    }

    /**
     * Records the request against the current database row. Returns false when that row must stay as it is.
     *
     * The check and the write are one update, so a stale in-memory model cannot replace a newer direction
     * request or leave its question behind. Ended tasks keep the reason but never ask for assistance.
     */
    public static function apply(Task $record, AssistanceKind $kind, ?string $question, string $reason, bool $replaceFailure = false, bool $replaceDirection = false): bool
    {
        $attributes = self::attributes($kind, $question, $reason);
        $updated = DB::table('tasks')->where('id', $record->id)
            ->where(function (Builder $query) use ($kind, $replaceFailure, $replaceDirection): void {
                $query->where('assistance_requested', false)
                    ->orWhere(function (Builder $open) use ($kind, $replaceFailure, $replaceDirection): void {
                        if (! $replaceDirection) {
                            $open->where(function (Builder $direction): void {
                                $direction->whereNull('assistance_kind')
                                    ->orWhere('assistance_kind', '!=', AssistanceKind::Direction->value);
                            });
                        }
                        if ($kind === AssistanceKind::Failure && ! $replaceFailure) {
                            $open->whereRaw('1 = 0');
                        } elseif ($replaceDirection) {
                            // An empty group would not match, so an intentional replacement needs a true condition.
                            $open->whereRaw('1 = 1');
                        }
                    });
            })
            ->update([
                'assistance_requested' => DB::raw("CASE WHEN status IN ('completed', 'cancelled') THEN 0 ELSE 1 END"),
                'assistance_kind' => $kind->value,
                'assistance_question' => $attributes['assistance_question'],
                'assistance_reason' => $reason,
                'updated_at' => now(),
            ]);

        $record->refresh();
        if ($updated > 0) {
            app(TaskBroadcasts::class)->groupChanged($record->parent_id ?? $record->id);
        }

        return $updated > 0;
    }

    public static function isBlockedReason(?string $reason): bool
    {
        return is_string($reason) && (str_starts_with($reason, self::ImplementerBlockedPrefix) || str_starts_with($reason, self::ReviewerBlockedPrefix));
    }

    /**
     * The question stored in a blocked reason: the text after the last `Question: `,
     * or the text after the blocked prefix when that marker is absent.
     */
    public static function questionFromBlockedReason(string $reason): string
    {
        $marker = 'Question: ';
        $position = strrpos($reason, $marker);
        if ($position !== false) {
            return substr($reason, $position + strlen($marker));
        }
        foreach ([self::ImplementerBlockedPrefix, self::ReviewerBlockedPrefix] as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return substr($reason, strlen($prefix));
            }
        }

        return $reason;
    }
}
