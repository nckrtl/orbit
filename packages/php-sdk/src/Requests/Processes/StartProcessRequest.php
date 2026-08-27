<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

final class StartProcessRequest extends ProcessActionRequest
{
    protected function action(): string
    {
        return 'start';
    }
}
