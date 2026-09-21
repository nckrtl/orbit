<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Models\Node;

interface ProxyCliPublicationManager
{
    public function converge(Node $node, int $port = ProxyCliProcess::PORT): void;

    public function remove(Node $node): void;
}
