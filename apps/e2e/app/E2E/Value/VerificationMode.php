<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum VerificationMode: string
{
    case Readiness = 'readiness';
    case Proof = 'proof';
}
