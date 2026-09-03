<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum ProofInputClassification: string
{
    case Runtime = 'runtime';
    case UnrelatedRuntime = 'unrelated-runtime';
    case ProofContract = 'proof-contract';
    case NonRuntime = 'non-runtime';
    case Indeterminate = 'indeterminate';
}
