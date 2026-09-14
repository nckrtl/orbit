<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Builder;

final readonly class RequireHerdrToolAction
{
    public function execute(Node $node): void
    {
        $installed = Tool::query()
            ->where('node_id', $node->id)
            ->where('package', 'herdr')
            ->where('status', ToolStatus::Installed)
            ->whereHas('manager', static function (Builder $query) use ($node): void {
                $query
                    ->where('node_id', $node->id)
                    ->where('name', ToolManagerName::Brew)
                    ->where('status', LifecycleStatus::Active);
            })
            ->exists();

        if ($installed) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'herdr.tool_not_installed',
            message: "Herdr is not installed as an active managed Tool on node [{$node->name}].",
            status: 409,
        );
    }
}
