<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Support\Console\InterruptIntent;
use App\Support\Realtime\FakeWebSocketTransport;
use App\Support\Realtime\RealtimeConnectionConfig;
use App\Support\Realtime\WebSocketTransport;

/**
 * Reads a recorded Pusher message stream from tests/Fixtures/realtime/<name>.ndjson: one
 * already-decoded message object per line.
 *
 * @return list<array<string, mixed>>
 */
function realtime_fixture_messages(string $name): array
{
    $path = __DIR__.'/../Fixtures/realtime/'.$name.'.ndjson';

    if (! is_file($path)) {
        throw new RuntimeException("Realtime fixture [{$name}] does not exist.");
    }

    $contents = trim((string) file_get_contents($path));

    if ($contents === '') {
        return [];
    }

    $messages = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Realtime fixture [{$name}] contains an invalid line.");
        }

        $messages[] = $decoded;
    }

    return $messages;
}

/** Build a FakeWebSocketTransport pre-loaded with one recorded Pusher message stream. */
function realtime_fixture_transport(string $name): FakeWebSocketTransport
{
    return new FakeWebSocketTransport(realtime_fixture_messages($name));
}

/** A resolved RealtimeConnectionConfig for a gateway at wss://reverb.test with key "app-key". */
function realtime_test_connection_config(): RealtimeConnectionConfig
{
    $profile = new GatewayProfile(
        name: 'test',
        url: 'https://gateway.test',
        caPath: null,
        realtimeUrl: 'wss://reverb.test',
        realtimeKey: 'app-key',
    );

    $config = RealtimeConnectionConfig::resolve($profile, '1.0.0');

    if ($config === null) {
        throw new RuntimeException('Realtime test fixture profile failed to resolve.');
    }

    return $config;
}

/**
 * Wraps a fixture transport so the first time receive() finds nothing left (a real socket
 * settling to idle once every recorded message has been delivered), it records a pending SIGINT
 * the same way a real Ctrl-C would. This lets a `realtime:tail` contract test run the command's
 * watch loop to a deterministic, fast stop once the fixture is exhausted, without sending the
 * test process an actual signal.
 */
function realtime_fixture_command_transport(string $name): WebSocketTransport
{
    return new class(realtime_fixture_transport($name)) implements WebSocketTransport
    {
        public function __construct(private readonly FakeWebSocketTransport $inner) {}

        #[Override]
        public function connect(string $url, ?string $caPath = null, float $timeoutSeconds = 10.0): void
        {
            $this->inner->connect($url, $caPath, $timeoutSeconds);
        }

        #[Override]
        public function send(array $message): void
        {
            $this->inner->send($message);
        }

        #[Override]
        public function receive(): ?array
        {
            $message = $this->inner->receive();

            if ($message === null) {
                InterruptIntent::record(SIGINT);
            }

            return $message;
        }

        #[Override]
        public function close(): void
        {
            $this->inner->close();
        }

        #[Override]
        public function isConnected(): bool
        {
            return $this->inner->isConnected();
        }
    };
}
