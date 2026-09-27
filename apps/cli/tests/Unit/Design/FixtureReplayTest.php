<?php

declare(strict_types=1);

use Design\Support\FixtureReplay;
use Illuminate\Filesystem\Filesystem;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('replays a recorded fixture without turning empty objects into lists', function (): void {
    $home = sys_get_temp_dir().'/orbit-fixture-replay-'.bin2hex(random_bytes(4));

    try {
        FixtureReplay::install('instances/instance-create/candidate-required', $home);

        $client = MockClient::getGlobal();
        expect($client)->not->toBeNull();

        $responses = (new ReflectionClass($client))->getProperty('requestResponses');
        $response = $responses->getValue($client)[CreateAppInstanceRequest::class] ?? null;

        expect($response)->toBeInstanceOf(MockResponse::class)
            ->and($response->body()->all())
            ->toContain('"details":{}')
            ->and(is_file($home.'/config.json'))->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('refuses a fixture record that is not a response object', function (string $contents): void {
    $root = sys_get_temp_dir().'/orbit-fixture-root-'.bin2hex(random_bytes(4));
    $home = sys_get_temp_dir().'/orbit-fixture-home-'.bin2hex(random_bytes(4));
    mkdir($root, 0700);
    file_put_contents($root.'/bad.json', $contents);

    try {
        expect(fn () => FixtureReplay::install('bad', $home, $root))
            ->toThrow(RuntimeException::class, 'Gateway fixture bad is not recorded.');
        expect(MockClient::getGlobal())->toBeNull()
            ->and($home)->not->toBeDirectory();
    } finally {
        new Filesystem()->deleteDirectory($root);
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    'list' => '[]',
    'text' => '"nope"',
    'request not text' => '{"request":1,"status":200,"body":{}}',
    'status not an integer' => '{"request":"ExampleRequest","status":"200","body":{}}',
    'status is fractional' => '{"request":"ExampleRequest","status":200.5,"body":{}}',
    'missing body' => '{"request":"ExampleRequest","status":200}',
]);

it('refuses a fixture name that is not recorded', function (): void {
    $home = sys_get_temp_dir().'/orbit-fixture-replay-'.bin2hex(random_bytes(4));

    try {
        expect(fn () => FixtureReplay::install('missing/fixture', $home))
            ->toThrow(RuntimeException::class, 'Gateway fixture missing/fixture is not recorded.');
        expect($home)->not->toBeDirectory();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});
