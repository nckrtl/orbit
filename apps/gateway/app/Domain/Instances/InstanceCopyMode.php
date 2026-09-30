<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final class InstanceCopyMode
{
    public const string Reflink = 'reflink';

    public const string Full = 'full';
}
