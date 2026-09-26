<?php

declare(strict_types=1);

use App\Infrastructure\Broadcasting\ReverbBroadcaster;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Pusher\Pusher;
use Pusher\PusherException;

it('sends non-ASCII text to Reverb at its UTF-8 size', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    $pusher = new Pusher('key', 'secret', '7', ['host' => 'gateway.orbit', 'port' => 443, 'scheme' => 'https'], new Client(['handler' => $stack]));
    $line = str_repeat('é', 1_000).str_repeat('日本', 500).str_repeat('😀', 500).' "quoted" a/b';
    $payload = ['type' => 'log.lines', 'data' => ['lines' => [$line]]];

    (new ReverbBroadcaster($pusher))->broadcast(['private-log-stream.ab'], 'log.lines', $payload);

    $request = $history[0]['request'];
    $body = (string) $request->getBody();
    parse_str($request->getUri()->getQuery(), $query);
    $sent = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    expect($body)->toBe(ReverbBroadcaster::body('log.lines', $payload, ['private-log-stream.ab']))
        ->and($body)->toContain('é日本', '本😀')
        ->and($query['body_md5'])->toBe(md5($body))
        ->and($sent['name'])->toBe('log.lines')
        ->and($sent['channels'])->toBe(['private-log-stream.ab'])
        ->and(json_decode($sent['data'], true, flags: JSON_THROW_ON_ERROR))->toBe($payload)
        // The line's own bytes, plus the escaped quotes and a small envelope.
        ->and(strlen($body))->toBeLessThan(strlen($line) + 200);
});

it('refuses an invalid channel name or socket ID before calling Reverb', function (array $channels, array $payload, string $message): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $pusher = new Pusher('key', 'secret', '7', ['host' => 'gateway.orbit', 'port' => 443, 'scheme' => 'https'], new Client(['handler' => $stack]));

    expect(fn () => (new ReverbBroadcaster($pusher))->broadcast($channels, 'log.lines', $payload))
        ->toThrow(PusherException::class, $message);

    expect($history)->toBeEmpty();
})->with([
    'invalid channel' => [['bad channel'], ['type' => 'log.lines'], 'Invalid channel name bad channel'],
    'invalid socket' => [['private-log-stream.ab'], ['type' => 'log.lines', 'socket' => '1234'], 'Invalid socket ID 1234'],
    'invalid integer socket' => [['private-log-stream.ab'], ['type' => 'log.lines', 'socket' => 1234], 'Invalid socket ID 1234'],
]);

it('posts a valid socket ID with the event', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    $pusher = new Pusher('key', 'secret', '7', ['host' => 'gateway.orbit', 'port' => 443, 'scheme' => 'https'], new Client(['handler' => $stack]));
    $payload = ['type' => 'log.lines', 'socket' => '1.2'];

    (new ReverbBroadcaster($pusher))->broadcast(['private-log-stream.ab'], 'log.lines', $payload);

    $body = (string) $history[0]['request']->getBody();

    expect($history)->toHaveCount(1)
        ->and($body)->toBe(ReverbBroadcaster::body('log.lines', ['type' => 'log.lines'], ['private-log-stream.ab'], ['socket_id' => '1.2']));
});

it('posts valid channels in groups of 100', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}'), new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    $pusher = new Pusher('key', 'secret', '7', ['host' => 'gateway.orbit', 'port' => 443, 'scheme' => 'https'], new Client(['handler' => $stack]));
    $channels = array_map(static fn (int $index): string => 'channel-'.$index, range(1, 101));

    (new ReverbBroadcaster($pusher))->broadcast($channels, 'log.lines', ['ok' => true]);

    $first = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $second = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($history)->toHaveCount(2)
        ->and($first['channels'])->toHaveCount(100)
        ->and($second['channels'])->toBe(['channel-101']);
});

it('is the reverb driver', function (): void {
    config(['broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => '7', 'options' => ['host' => 'gateway.orbit']]]);
    $manager = app(BroadcastManager::class);
    $manager->purge('reverb');

    expect($manager->connection('reverb'))->toBeInstanceOf(ReverbBroadcaster::class);
});

it('gives up on a slow Reverb within a few seconds', function (): void {
    config([
        'broadcasting.connections.reverb.key' => 'key',
        'broadcasting.connections.reverb.secret' => 'secret',
        'broadcasting.connections.reverb.app_id' => '7',
        'broadcasting.connections.reverb.options.host' => 'gateway.orbit',
    ]);

    $broadcaster = app(BroadcastManager::class)->connection('reverb');
    assert($broadcaster instanceof ReverbBroadcaster);
    $pusher = $broadcaster->getPusher();
    assert($pusher instanceof Pusher);

    expect($pusher->getSettings()['timeout'])->toBe(3)
        ->and(config('broadcasting.connections.reverb.client_options'))->toMatchArray(['connect_timeout' => 2, 'timeout' => 3]);
});
