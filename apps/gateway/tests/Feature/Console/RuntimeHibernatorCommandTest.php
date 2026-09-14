<?php

declare(strict_types=1);

use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;

it('reports how many idle AppInstance groups the hibernator halted', function (): void {
    $this->mock(SweepIdleAppDevRuntimesAction::class, function ($mock): void {
        $mock->shouldReceive('execute')->once()->andReturn(2);
    });

    $this->artisan('orbit:runtime-hibernator')
        ->expectsOutput('Halted [2] idle app-dev AppInstance runtime groups.')
        ->assertSuccessful();
});
