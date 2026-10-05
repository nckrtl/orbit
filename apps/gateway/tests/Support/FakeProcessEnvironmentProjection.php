<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Models\Instance;

final class FakeProcessEnvironmentProjection implements ProcessEnvironmentProjection
{
    /** @var list<array{instance_id: int, except_process_id: int}> */
    public array $projected = [];

    public function project(Instance $instance, int $exceptProcessId, ?string $app = null): void
    {
        $this->projected[] = ['instance_id' => $instance->id, 'except_process_id' => $exceptProcessId];
    }
}
