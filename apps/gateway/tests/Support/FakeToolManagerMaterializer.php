<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use Closure;

final class FakeToolManagerMaterializer implements ToolManagerMaterializer
{
    /** @var list<list<ToolManagerName>> */
    public array $requests = [];

    /** @var list<string> */
    public array $events = [];

    public ?NodeProvisioningException $failure = null;

    public function converge(Node $node, ToolManagerName ...$managerNames): void
    {
        $this->requests[] = array_values($managerNames);
        $names = array_map(static fn (ToolManagerName $name): string => $name->value, $managerNames);
        $status = $node->roles()->whereIn('role', ['app-dev', 'app-prod'])->first()->status ?? $node->status;
        $this->events[] = implode(',', $names).":{$status->value}";

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /** @param Closure(NodeProvisioningException): void $onFailure */
    public function convergeWithFailureHandler(Node $node, Closure $onFailure, ToolManagerName ...$managerNames): void
    {
        try {
            $this->converge($node, ...$managerNames);
        } catch (NodeProvisioningException $exception) {
            $onFailure($exception);
            throw $exception;
        }
    }
}
