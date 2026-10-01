<?php

declare(strict_types=1);

namespace App\Domain\Instances\DependencyCopy;

use App\Models\Instance;

/**
 * Copies the dependency directories of one development checkout into another on the same Node,
 * as a reflink where the filesystem supports block cloning. A failure throws
 * `instance.dependency_copy_failed`.
 */
interface InstanceDependencyCopier
{
    /** The directories a new checkout gets from the `default` Instance. */
    public const array DIRECTORIES = ['vendor', 'node_modules'];

    public function copy(Instance $source, Instance $target): void;
}
