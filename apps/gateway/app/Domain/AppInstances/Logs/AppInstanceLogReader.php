<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Logs;

use App\Models\AppInstance;

interface AppInstanceLogReader
{
    /**
     * The last lines of the application log under the instance's `storage/logs`:
     * `laravel.log`, or the newest `laravel-*.log` of a daily channel. An instance
     * without such a file has an empty log.
     */
    public function tail(AppInstance $instance, int $lines): string;
}
