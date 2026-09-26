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

it('is the reverb driver', function (): void {
    config(['broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => '7', 'options' => ['host' => 'gateway.orbit']]]);
    $manager = app(BroadcastManager::class);
    $manager->purge('reverb');

    expect($manager->connection('reverb'))->toBeInstanceOf(ReverbBroadcaster::class);
});
