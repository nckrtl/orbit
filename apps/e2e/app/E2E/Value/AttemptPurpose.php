<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** Why an attempt topology exists. */
enum AttemptPurpose: string
{
    case Discovery = 'discovery';
    case Proof = 'proof';
    case CandidateConvergence = 'candidate-convergence';
}
