<?php

declare(strict_types=1);

namespace App\Domain\Instances\Logs;

use App\Models\Instance;

interface InstanceLogReader
{
    /**
     * The last lines of the application log under the instance's `storage/logs`:
     * `laravel.log`, or the newest `laravel-*.log` of a daily channel. An instance
     * without such a file has an empty log.
     */
    public function tail(Instance $instance, int $lines): string;
}
