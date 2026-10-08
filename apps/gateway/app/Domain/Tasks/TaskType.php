<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskType: string
{
    case Implementation = 'implementation';
    case Annotation = 'annotation';

    /** ADR 0203: Orbit's review of the whole branch before it pushes. It has no implementer. */
    case FinalReview = 'final_review';
}
