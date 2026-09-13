<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

enum DnsRecordType: int
{
    case A = 1;
    case Ns = 2;
    case Cname = 5;
    case Soa = 6;
    case Aaaa = 28;
    case Opt = 41;
}
