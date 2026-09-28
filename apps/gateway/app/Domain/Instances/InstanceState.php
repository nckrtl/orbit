<?php

declare(strict_types=1);

namespace App\Domain\Instances;

enum InstanceState: string
{
    case Reserved = 'reserved';
    case CheckoutPrepared = 'checkout_prepared';
    case SourceResolved = 'source_resolved';
    case Active = 'active';
    case Removing = 'removing';
}
