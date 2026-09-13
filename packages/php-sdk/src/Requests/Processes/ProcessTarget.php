<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

interface ProcessTarget
{
    /** @return array{target_type: string, target_id: int} */
    public function toRequestData(): array;
}
