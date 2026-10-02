<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\DependencyCopy\InstanceDependencyCopier;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Closure;

final class FakeInstanceDependencyCopier implements InstanceDependencyCopier
{
    /** @var list<array{source: string, target: string}> */
    public array $copies = [];

    public bool $fails = false;

    /** Runs before the copy is recorded, so a test can see what happened first. */
    public ?Closure $onCopy = null;

    public function copy(Instance $source, Instance $target): void
    {
        if ($this->onCopy instanceof Closure) {
            ($this->onCopy)();
        }

        $this->copies[] = ['source' => $source->name, 'target' => $target->name];

        if ($this->fails) {
            throw new ResourceOperationException('instance.dependency_copy_failed', 'The dependency directories could not be copied.', 502);
        }
    }
}
