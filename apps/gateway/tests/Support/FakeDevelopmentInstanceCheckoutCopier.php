<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\DevelopmentInstanceCopyInspection;
use App\Domain\Instances\DevelopmentInstanceCopyResult;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final class FakeDevelopmentInstanceCheckoutCopier implements DevelopmentInstanceCheckoutCopier
{
    public int $inspections = 0;

    public int $copies = 0;

    public int $discards = 0;

    public int $markerDeletes = 0;

    public ?string $failInspect = null;

    public ?string $failCopy = null;

    public bool $copyStarts = false;

    public string $mode = 'reflink';

    public string $head;

    public ?string $environmentFile = null;

    public ?string $branch = null;

    public ?string $expectedHead = null;

    public function __construct()
    {
        $this->head = str_repeat('c', 40);
    }

    public function inspect(Instance $source, string $branch): DevelopmentInstanceCopyInspection
    {
        $this->inspections++;
        $this->branch = $branch;

        if ($this->failInspect !== null) {
            throw new ResourceOperationException($this->failInspect, 'The source cannot be copied.', 409);
        }

        return new DevelopmentInstanceCopyInspection($this->head);
    }

    public function copy(
        Instance $source,
        Instance $target,
        string $branch,
        string $expectedHead,
        string $occupiedCode,
    ): DevelopmentInstanceCopyResult {
        $this->copies++;
        $this->branch = $branch;
        $this->expectedHead = $expectedHead;

        if ($this->failCopy !== null) {
            throw new ResourceOperationException(
                errorCode: $this->failCopy,
                message: 'The copy failed.',
                status: 409,
                details: $this->copyStarts ? ['copy_started' => '1'] : [],
            );
        }

        return new DevelopmentInstanceCopyResult($this->mode, $this->head);
    }

    public function readEnvironment(Instance $target): ?string
    {
        return $this->environmentFile;
    }

    public function deleteMarker(Instance $target): void
    {
        $this->markerDeletes++;
    }

    public function discardPartial(Instance $target): void
    {
        $this->discards++;
    }
}
