<?php

declare(strict_types=1);

use App\Http\Middleware\RecordCommandActivity;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('requires a matched route before it records a command', function (): void {
    $request = Request::create('/api/example', 'POST');
    $request->setRouteResolver(static fn (): null => null);

    expect(fn () => app(RecordCommandActivity::class)->handle($request, static fn (): StreamedResponse => new StreamedResponse))
        ->toThrow(LogicException::class, 'Command activity requires a matched route.');

    expect(Activity::query()->count())->toBe(0);
});

it('fails a stream whose callback was never set', function (): void {
    $request = Request::create('/api/example', 'POST');
    $route = new Route(['POST'], '/api/example', static fn (): string => 'ok');
    $route->name('example:stream');
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    $response = app(RecordCommandActivity::class)->handle(
        $request,
        static fn (): StreamedResponse => new StreamedResponse,
    );

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect(fn () => $response->sendContent())
        ->toThrow(LogicException::class, 'The Response callback must be set.');

    $activity = Activity::query()->sole();
    expect($activity->command)->toBe('example:stream')
        ->and($activity->status)->toBe('failed');
});
