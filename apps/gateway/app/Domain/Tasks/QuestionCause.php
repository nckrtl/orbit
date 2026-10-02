<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum QuestionCause: string
{
    case BriefUnclear = 'brief_unclear';
    case ContractGap = 'contract_gap';
    case Scope = 'scope';
    case Environment = 'environment';
    case MissedContract = 'missed_contract';
}
