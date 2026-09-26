<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

/** The Instance or Process whose log a live log stream follows. */
interface LogStreamTarget
{
    /** The collection path of the target's log streams, for example `/api/v1/processes/41/log-streams`. */
    public function logStreamsPath(): string;
}
