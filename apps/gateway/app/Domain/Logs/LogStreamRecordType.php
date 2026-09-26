<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/** The kind of record whose log a live log stream follows (ADR 0153). */
enum LogStreamRecordType: string
{
    case Instance = 'instance';
    case Process = 'process';
}
