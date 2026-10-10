<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final class FakeProductionRepositoryBinding implements ProductionRepositoryBinding
{
    /** @var array<int, ProductionRepositoryRecord> */
    public array $records = [];

    /** @var list<array{int, ProductionRepositoryRecord, ProductionRepositoryRecord}> */
    public array $rebinds = [];

    public bool $failRebind = false;

    public function inspect(Instance $instance): ProductionRepositoryRecord
    {
        return $this->records[$instance->id] ?? new ProductionRepositoryRecord(null, null, []);
    }

    public function rebind(Instance $instance, ProductionRepositoryRecord $expected, ProductionRepositoryRecord $target): void
    {
        $this->rebinds[] = [$instance->id, $expected, $target];

        if ($this->failRebind) {
            throw new ResourceOperationException('project.production_rebind_failed', 'Re-binding failed.', 409);
        }

        $this->records[$instance->id] = $target;
    }
}
