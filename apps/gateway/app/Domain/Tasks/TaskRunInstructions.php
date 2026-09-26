<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Tells an agent how to end its turn with the run script.
 */
final readonly class TaskRunInstructions
{
    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @param  string|null  $check  the Project task check command, or null when the Project has none (ADR 0125)
     */
    public static function implementer(array $deliverables = [], ?string $check = 'composer check'): string
    {
        $passes = $check === null ? '' : ' and '.$check.' passes';
        $confirm = $deliverables === [] ? '' : ' Add --deliverable=ID=evidence for each deliverable of this subtask ('.self::ids($deliverables).'), where the evidence says where or how it is met. Orbit refuses the handoff without them, then checks file, test, and command deliverables against your diff and its own run.';

        return self::autonomy().' When the brief is complete'.$passes.', end your turn with .git/orbit/run --outcome=ready_for_review --summary="What you changed".'.$confirm.' '.self::blocked();
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

        return 'This review is read-only. Do not create, edit, reset, or delete workspace files, including disposable fixtures. Request changes from the implementer instead. Do not commit; Orbit commits after you approve. '.$approve.' Otherwise end your turn with .git/orbit/run --outcome=changes_requested --summary="The findings the implementer must address". '.self::blocked();
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

    private static function autonomy(): string
    {
        return 'Complete your assigned work autonomously. You may create, modify, reset, and delete disposable fixtures within your task\'s allocated environment, including Routes and publications, without asking for permission. Verify task ownership and the target environment before deletion, use the required CLI confirmation flags, and follow the environment\'s lease and cleanup rules. This authority does not extend to live or shared resources or another task\'s fixtures. Resolve routine test prerequisites yourself.';
    }

    /**
     * A blocked turn pauses the whole group until the operator answers, so it must ask one specific question.
     */
    private static function blocked(): string
    {
        return 'Ask for help only when ownership is uncertain, an action affects live or shared resources beyond the task\'s authorization, required access is missing, or a product decision needs the operator. If one of these boundaries prevents further progress, end your turn with .git/orbit/run --outcome=blocked --summary="What stops you, what you tried, and the boundary you cannot cross" --question="One specific question the operator can answer". A blocked turn pauses the group until the operator answers. If you can decide or find the answer yourself, keep working instead.';
    }

    /** @param list<TaskDeliverable> $deliverables */
    private static function ids(array $deliverables): string
    {
        return implode(', ', array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables));
    }
}
