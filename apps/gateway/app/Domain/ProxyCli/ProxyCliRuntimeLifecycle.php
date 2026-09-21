<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Models\Node;
use SensitiveParameter;

interface ProxyCliRuntimeLifecycle
{
    /**
     * @param  array<string, string>  $environment
     */
    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
        int $port = ProxyCliProcess::PORT,
    ): void;

    public function remove(Node $node): void;
}
