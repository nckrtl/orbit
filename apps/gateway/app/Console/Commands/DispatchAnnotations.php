<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Annotations\DispatchAnnotationsAction;
use Illuminate\Console\Command;

final class DispatchAnnotations extends Command
{
    #[\Override]
    protected $signature = 'annotations:dispatch';

    #[\Override]
    protected $description = 'Deliver pending Instance annotations to idle T3 threads.';

    public function handle(DispatchAnnotationsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
