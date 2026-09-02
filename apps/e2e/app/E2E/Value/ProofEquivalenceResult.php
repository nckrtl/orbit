<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum ProofEquivalenceResult: string
{
    case Exact = 'exact';
    case Equivalent = 'equivalent';
    case Stale = 'stale';
    case Indeterminate = 'indeterminate';
}
