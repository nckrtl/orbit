<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** The verdict of a proof attempt: the candidate is proved, or it produced a diagnosis. */
enum ProofStatus: string
{
    case Proved = 'proved';
    case Diagnosis = 'diagnosis';
}
