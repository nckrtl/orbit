<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

final class RestartProcessRequest extends ProcessActionRequest
{
    protected function action(): string
    {
        return 'restart';
    }
}
