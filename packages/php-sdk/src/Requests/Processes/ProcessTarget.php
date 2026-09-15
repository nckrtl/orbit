<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

interface ProcessTarget
{
    /** @return array{target_type: string, target_id: int|string} */
    public function toRequestData(): array;
}
