<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum ProofReleaseReason: string
{
    case Replacement = 'replacement';
    case Abandonment = 'abandonment';
}
