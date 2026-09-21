<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskSessionNextAction: string
{
    case DrainApproval = 'drain_approval';
    case DrainUserInput = 'drain_user_input';
    case ContinueImplementer = 'continue_implementer';
    case RelayReviewToImplementer = 'relay_review_to_implementer';
    case MarkSubtaskDone = 'mark_subtask_done';
    case SettleGroup = 'settle_group';
    case EscalateCoder = 'escalate_coder';
    case Noop = 'noop';

    /** @return array<string, string> */
    public static function choiceCriteria(): array
    {
        return [
            self::DrainApproval->value => 'A stored task thread is waiting on an approval request id.',
            self::DrainUserInput->value => 'A stored task thread is waiting on a user-input request id.',
            self::ContinueImplementer->value => 'The implementer is idle, done, or failed with unfinished work on the current brief.',
            self::RelayReviewToImplementer->value => 'The reviewer left a summary and the implementer is idle, done, or failed.',
            self::MarkSubtaskDone->value => 'The current subtask has verified completion evidence beyond its thread state and is ready for review or reviewer sign-off.',
            self::SettleGroup->value => 'The last subtask is verified and the group is ready to settle with a pull request.',
            self::EscalateCoder->value => 'The facts are insufficient, conflicting, or need a human.',
            self::Noop->value => 'The session is already working or needs no scheduler action.',
        ];
    }
}
