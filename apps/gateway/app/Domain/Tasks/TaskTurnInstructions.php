<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;

/**
 * Tells an agent how to end its turn with the turn command.
 */
final readonly class TaskTurnInstructions
{
    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @param  string|null  $check  the Project task check command, or null when the Project has none (ADR 0125)
     */
    public static function implementer(array $deliverables = [], ?string $check = null, ?int $threadId = null): string
    {
        $passes = $check === null ? '' : ' and '.$check.' passes';
        $gate = $check === null ? '' : ' Orbit runs '.$check.' again at handoff with access you do not have, such as sudo. When it fails for you only because you lack that access, hand off anyway.';
        $confirm = $deliverables === [] ? '' : ' Add --deliverable=ID=evidence for each deliverable of this subtask ('.self::ids($deliverables).'), where the evidence says where or how it is met. Orbit refuses the handoff without them, then checks file and command deliverables against your diff and its own run.';

        return self::origin().' '.self::autonomy().' When the brief is complete'.$passes.', end your turn with '.self::command($threadId, '--outcome=ready_for_review --summary="What you changed"').'.'.$gate.$confirm.' '.self::blocked($threadId);
    }

    /**
     * The approval of the last subtask also describes the pull request Orbit opens.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public static function reviewer(bool $final = false, array $deliverables = [], ?int $threadId = null): string
    {
        $approve = $final
            ? 'This is the last subtask. When it meets its brief, end your turn with '.self::command($threadId, '--outcome=approved --summary="What you checked" --pr-summary="One or two sentences about the whole feature" --pr-change="A new feature or behavior change" --pr-breaking="A breaking change"').'. Repeat --pr-change for each change in the feature, and --pr-breaking for each breaking change, or pass --pr-breaking=none. The change list, summary and breaking list are yours to write: add a missing entry yourself instead of requesting changes.'
            : 'When the subtask meets its brief, end your turn with '.self::command($threadId, '--outcome=approved --summary="What you checked"').'.';
        $reviews = array_values(array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $deliverable->type === TaskDeliverableType::Review));
        if ($reviews !== []) {
            $approve .= ' The approval must confirm each review deliverable ('.self::ids($reviews).') with --deliverable=ID=evidence, where the evidence says what you checked.';
        }

        return self::origin().' This review is read-only. Do not create, edit, reset, or delete workspace files, including disposable fixtures. Request changes from the implementer instead. Do not commit; Orbit commits after you approve. Report a missing guarantee against injected failures, such as a lost response or a crash between two writes, as a finding when this subtask adds or changes that state transition, or when the brief, an ADR, or a deliverable names it; otherwise list it as a follow-up in your summary. '.$approve.' Otherwise end your turn with '.self::command($threadId, '--outcome=changes_requested --summary="The findings the implementer must address"').'. When this review follows an operator direction, every outcome also needs --cause. '.self::blocked($threadId, true);
    }

    /** The reviewer answers an implementer's question from the contract, before the operator is asked. */
    public static function consult(?int $threadId = null): string
    {
        return self::origin().' This is a consult, not a review. Answer from the brief, the ADRs, the documentation, the code, and the task history. End your turn with '.self::command($threadId, '--outcome=answered --summary="The answer for the implementer" --cause=CAUSE').', or '.self::command($threadId, '--outcome=blocked --summary="Why the contract cannot answer it" --question="One specific question" --cause=CAUSE').'. '.self::causes();
    }

    /** The reviewer translates an operator's direction before the implementer continues. */
    public static function relay(?int $threadId = null): string
    {
        return self::origin().' This is a relay of the operator\'s direction, not a review. End your turn with '.self::command($threadId, '--outcome=answered --summary="The answer for the implementer" --cause=CAUSE').', or '.self::command($threadId, '--outcome=blocked --summary="Why the contract cannot answer it" --question="One specific question" --cause=CAUSE').'. '.self::causes();
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
     * The workspace commit the group started at, and the stat from there to HEAD.
     * Empty unless the commit is the 40 or 64 hexadecimal characters Orbit records.
     */
    public static function groupStart(string $commit): string
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            return '';
        }

        return 'The group started at '.$commit.".\n".'git diff --stat '.$commit.'..HEAD';
    }

    private static function origin(): string
    {
        return 'Orbit fetches origin before every agent turn. Do not fetch or push. Orbit publishes the approved commit itself.';
    }

    private static function autonomy(): string
    {
        return 'Complete your assigned work autonomously. You may create, modify, reset, and delete disposable fixtures within your task\'s allocated environment without asking for permission. This authority does not extend to live or shared resources or another task\'s fixtures. Resolve routine test prerequisites yourself.';
    }

    private static function command(?int $threadId, string $arguments): string
    {
        return '"$(git rev-parse --git-path orbit)/turn" '.($threadId === null ? '' : '--thread='.$threadId.' ').$arguments;
    }

    private static function causes(): string
    {
        return 'CAUSE is one of brief_unclear, contract_gap, scope, environment, or missed_contract.';
    }

    /**
     * An implementer's blocked turn asks the reviewer first. A reviewer's blocked turn asks the operator.
     */
    private static function blocked(?int $threadId, bool $reviewer = false): string
    {
        if ($reviewer) {
            return 'A blocked review asks the operator for direction. End it with '.self::command($threadId, '--outcome=blocked --summary="What the contract cannot answer" --question="One specific question" --cause=CAUSE').'. '.self::causes().' If you can decide from the brief, the ADRs, the documentation, the code, or the task history, keep working instead.';
        }

        return 'Ask for help only when ownership is uncertain, an action affects live or shared resources beyond the task\'s authorization, required access is missing, or a product decision needs the operator. If one of these boundaries prevents further progress, end your turn with '.self::command($threadId, '--outcome=blocked --summary="What stops you, what you tried, and the boundary you cannot cross" --question="One specific question"').'. A blocked turn starts a consult: Orbit sends the summary and the question to the subtask\'s reviewer before the operator. The reviewer answers with answered and you continue the same attempt, or the reviewer asks the operator. The reviewer sets --cause to one of brief_unclear, contract_gap, scope, environment, or missed_contract. A third block in one attempt asks the operator at once. If you can decide or find the answer yourself, keep working instead.';
    }

    /** @param list<TaskDeliverable> $deliverables */
    private static function ids(array $deliverables): string
    {
        return implode(', ', array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables));
    }
}
