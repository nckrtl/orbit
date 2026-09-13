<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

enum DnsRequesterIdentity: string
{
    case Registered = 'registered';
    case Unidentified = 'unidentified';
}
