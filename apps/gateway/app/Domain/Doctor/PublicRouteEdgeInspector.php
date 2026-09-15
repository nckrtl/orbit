<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use App\Models\Route;

interface PublicRouteEdgeInspector
{
    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation;
}
