<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/** The three log sources a Node agent may read for a live log stream, and nothing else (ADR 0153). */
enum LogSourceType: string
{
    /** `storage/logs/laravel.log`, or the newest `laravel-*.log`, in an Instance checkout. */
    case Laravel = 'laravel';

    /** The journal entries of one `orbit-process-*.service` unit. */
    case Journal = 'journal';

    /** The output of one `orbit-process-*` container. */
    case Docker = 'docker';
}
