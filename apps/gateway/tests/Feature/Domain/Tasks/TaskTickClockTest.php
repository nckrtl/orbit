<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskTickClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

it('records and reads the start of the last tick', function (): void {
    $this->travelTo(Carbon::parse('2026-10-07T06:00:00Z'));
    $clock = new TaskTickClock;

    expect($clock->lastStartedAt())->toBeNull();

    $clock->record();

    expect($clock->lastStartedAt())->toBe('2026-10-07T06:00:00.000000Z');
});

it('reports a cache that cannot be written or read without failing the tick or the status', function (): void {
    Exceptions::fake();
    Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('The cache could not be written.'));
    Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('The cache could not be read.'));
    $clock = new TaskTickClock;

    $clock->record();

    expect($clock->lastStartedAt())->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
