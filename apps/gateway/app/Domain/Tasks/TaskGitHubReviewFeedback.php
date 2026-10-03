<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Independent source causes survive repair activation and unrelated operator assistance. */
final readonly class TaskGitHubReviewFeedback
{
    public const string Prefix = 'GitHub review feedback: ';

    public static function isReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::Prefix);
    }

    /** @return array<string, string> */
    public function causes(Task $group): array
    {
        $encoded = DB::table('task_github_review_feedback')->where('group_id', $group->id)->value('causes');
        if ($encoded === null) {
            return [];
        }
        if (! is_string($encoded)) {
            throw new LogicException('Invalid review feedback causes.');
        }
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Invalid review feedback causes.');
        }
        $causes = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new LogicException('Invalid review feedback cause.');
            }
            $causes[$key] = $value;
        }

        return $causes;
    }

    /** Set or recover exactly one source. An empty update republishes retained causes when possible.
     * @param  array<string, string|null>  $changes
     * @return bool Whether the displayed assistance changed. Notifications happen outside this transaction.
     */
    public function change(Task $group, array $changes = []): bool
    {
        $changed = DB::transaction(function () use ($group, $changes): bool {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $causes = $this->causes($locked);
            foreach ($changes as $source => $reason) {
                if ($reason === null) {
                    unset($causes[$source]);
                } else {
                    $causes[$source] = $reason;
                }
            }
            ksort($causes);
            DB::table('task_github_review_feedback')->updateOrInsert(['group_id' => $locked->id], [
                'causes' => json_encode($causes, JSON_THROW_ON_ERROR),
            ]);
            if ($locked->assistance_requested && ! self::isReason($locked->assistance_reason)) {
                return false;
            }
            $reason = $causes === [] ? null : self::Prefix.implode(' ', $causes);
            if ($reason === $locked->assistance_reason || in_array($locked->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)) {
                return false;
            }
            $attributes = $reason === null ? TaskAssistance::cleared() : TaskAssistance::attributes(AssistanceKind::Failure, null, $reason);
            DB::table('tasks')->where('id', $locked->id)->update([
                ...$attributes, 'assistance_kind' => $reason === null ? null : AssistanceKind::Failure->value, 'updated_at' => now(),
            ]);

            return true;
        });
        $group->refresh();

        return $changed;
    }
}
