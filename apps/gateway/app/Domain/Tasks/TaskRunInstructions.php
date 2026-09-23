<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Tells an agent how to end its turn with the run script.
 */
final readonly class TaskRunInstructions
{
    /** @param list<TaskDeliverable> $deliverables */
    public static function implementer(array $deliverables = []): string
    {
        $confirm = $deliverables === [] ? '' : ' Add --deliverable=ID=evidence for each deliverable of this subtask ('.self::ids($deliverables).'), where the evidence says where or how it is met. Orbit refuses the handoff without them, then checks file, test, and command deliverables against your diff and its own run.';

        return 'When the brief is complete and composer check passes, end your turn with .git/orbit/run --outcome=ready_for_review --summary="What you changed".'.$confirm.' '.self::blocked('brief');
    }

    /**
     * The approval of the last subtask also describes the pull request Orbit opens.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public static function reviewer(bool $final = false, array $deliverables = []): string
    {
        $approve = $final
            ? 'This is the last subtask. When it meets its brief, end your turn with .git/orbit/run --outcome=approved --summary="What you checked" --pr-summary="One or two sentences about the whole feature" --pr-change="A new feature or behavior change" --pr-breaking="A breaking change". Repeat --pr-change for each change in the feature, and --pr-breaking for each breaking change, or pass --pr-breaking=none.'
            : 'When the subtask meets its brief, end your turn with .git/orbit/run --outcome=approved --summary="What you checked".';
        $reviews = array_values(array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $deliverable->type === TaskDeliverableType::Review));
        if ($reviews !== []) {
            $approve .= ' The approval must confirm each review deliverable ('.self::ids($reviews).') with --deliverable=ID=evidence, where the evidence says what you checked.';
        }

        return 'Do not commit; Orbit commits after you approve. '.$approve.' Otherwise end your turn with .git/orbit/run --outcome=changes_requested --summary="The findings the implementer must address". '.self::blocked('review');
    }

    /**
     * The deliverables as a list for an agent prompt, or an empty string without any.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public static function deliverables(array $deliverables): string
    {
        if ($deliverables === []) {
            return '';
        }

        return "Deliverables. Orbit checks each one before the review:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
    }

    /**
     * A blocked turn pauses the whole group until the operator answers, so it must ask one specific question.
     */
    private static function blocked(string $work): string
    {
        return 'Only if something outside the '.$work.' stops you and you need the operator to decide, end your turn with .git/orbit/run --outcome=blocked --summary="What stops you" --question="One specific question the operator can answer". A blocked turn pauses the group until the operator answers. If you can decide or find the answer yourself, keep working instead.';
    }

    /** @param list<TaskDeliverable> $deliverables */
    private static function ids(array $deliverables): string
    {
        return implode(', ', array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables));
    }
}
