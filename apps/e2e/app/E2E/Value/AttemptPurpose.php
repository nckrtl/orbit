<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** Why an attempt topology exists: to explore a change, or to prove an exact candidate. */
enum AttemptPurpose: string
{
    case Discovery = 'discovery';
    case Proof = 'proof';
}
