<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Models\Node;
use Illuminate\Support\Str;
use Psr\Log\NullLogger;

/**
 * A scripted TLS WebSocket server in a child process, with a certificate for `reverb.orbit`
 * that only this test trusts.
 */
final class ScriptedWebSocketServer
{
    public readonly int $port;

    /** @var resource */
    private mixed $process;

    /** @var resource */
    private mixed $stdout;

    /** @var list<string> */
    private array $lines = [];

    public function __construct(public readonly string $caPath, string $keyPath, string $mode, array $arguments = [])
    {
        $command = [PHP_BINARY, base_path('tests/Fixtures/AgentView/websocket-server.php'), $caPath, $keyPath, $mode, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('The test WebSocket server did not start.');
        }

        $this->process = $process;
        $this->stdout = $pipes[1];
        $port = $this->waitFor('port=');
        $this->port = (int) substr($port, 5);
    }

    public static function start(string $mode, array $arguments = []): self
    {
        $directory = sys_get_temp_dir().'/orbit-ws-'.Str::random(8);
        mkdir($directory);
        $config = $directory.'/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=DNS:reverb.orbit\nkeyUsage=digitalSignature,keyCertSign\n");
        $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'reverb.orbit'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export_to_file($certificate, $directory.'/cert.pem');
        openssl_pkey_export_to_file($key, $directory.'/key.pem', null, ['config' => $config]);

        return new self($directory.'/cert.pem', $directory.'/key.pem', $mode, $arguments);
    }

    public function endpoint(string $serverName = 'reverb.orbit'): WebSocketEndpoint
    {
        return new WebSocketEndpoint('127.0.0.1', $this->port, $serverName, '/app/key?protocol=7', $this->caPath);
    }

    /** Waits for the next server line that starts with $prefix. */
    public function waitFor(string $prefix, float $seconds = 10.0): string
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            foreach ($this->lines as $index => $line) {
                if (str_starts_with($line, $prefix)) {
                    unset($this->lines[$index]);

                    return $line;
                }
            }

            $read = [$this->stdout];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 0, 100_000) > 0) {
                $line = fgets($this->stdout);

                if ($line === false) {
                    break;
                }

                $this->lines[] = rtrim($line);
            }
        }

        throw new RuntimeException("The test WebSocket server never printed [{$prefix}].");
    }

    /** Waits until the server process has exited, so a reset it sent has reached the client. */
    public function waitForExit(float $seconds = 5.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (proc_get_status($this->process)['running']) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The test WebSocket server did not exit.');
            }

            usleep(10_000);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}

/**
 * Receives until $count messages arrived or the client closed.
 *
 * @return list<array<string, mixed>>
 */
function websocket_messages(StreamWebSocketClient $client, int $count, float $seconds = 10.0): array
{
    $messages = [];
    $deadline = microtime(true) + $seconds;

    while (count($messages) < $count && $client->isConnected() && microtime(true) < $deadline) {
        array_push($messages, ...$client->receive(0.2));
    }

    return $messages;
}

describe(StreamWebSocketClient::class, function (): void {
    it('completes the TLS and WebSocket handshake against the pinned certificate', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            expect($client->isConnected())->toBeTrue()
                ->and($server->waitFor('request='))->toBe('request=GET /app/key?protocol=7 HTTP/1.1');
        } finally {
            $server->stop();
        }
    });

    it('refuses a certificate for another name', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            expect(fn () => (new StreamWebSocketClient)->connect($server->endpoint('gateway.orbit'), 5.0))
                ->toThrow(WebSocketException::class, 'Could not connect to Reverb');
        } finally {
            $server->stop();
        }
    });

    it('refuses a refused handshake and a wrong accept key', function (string $mode, string $message): void {
        $server = ScriptedWebSocketServer::start($mode);

        try {
            $client = new StreamWebSocketClient;

            expect(fn () => $client->connect($server->endpoint(), 5.0))->toThrow(WebSocketException::class, $message)
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    })->with([
        'refused' => ['refuse', 'refused the WebSocket handshake'],
        'wrong accept key' => ['bad-accept', 'unexpected WebSocket accept key'],
    ]);

    it('returns whole JSON messages and drops oversized, malformed, and binary ones', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            $events = array_column(websocket_messages($client, 3), 'event');

            // `too-big` and the fragmented `big-fragment`, including its tail frame, pass 64 KB.
            expect($events)->toBe(['one', 'fragmented', 'two'])
                ->and($server->waitFor('pong='))->toBe('pong=p');

            $client->send(['event' => 'hello', 'data' => ['text' => str_repeat('z', 300)]]);
            $echo = websocket_messages($client, 1)[0];

            expect(json_decode((string) $echo['data'], true))->toBe(['event' => 'hello', 'data' => ['text' => str_repeat('z', 300)]]);

            websocket_messages($client, 1, 3.0);

            expect($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('closes the connection when a frame claims more than 1 MiB', function (): void {
        $server = ScriptedWebSocketServer::start('huge');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            expect(websocket_messages($client, 1, 3.0))->toBe([])
                ->and($client->isConnected())->toBeFalse()
                ->and($server->waitFor('closed='))->toBe('closed=yes');
        } finally {
            $server->stop();
        }
    });

    it('treats a connection reset as a closed connection', function (): void {
        $server = ScriptedWebSocketServer::start('reset');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('reset=');
            $server->waitForExit();

            expect(websocket_messages($client, 1, 3.0))->toBe([])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('delivers a message that arrived before a connection reset', function (): void {
        $server = ScriptedWebSocketServer::start('reset-after-frame');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('reset=');
            $server->waitForExit();

            expect($client->receive(1.0))->toBe([['event' => 'last']])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('delivers a message that arrived before a read error', function (): void {
        $server = ScriptedWebSocketServer::start('corrupt-after-frame');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('corrupted=');
            usleep(100_000);

            expect($client->receive(1.0))->toBe([['event' => 'last']])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('closes instead of throwing when it cannot answer a ping', function (): void {
        $server = ScriptedWebSocketServer::start('ping');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            stream_socket_shutdown((new ReflectionProperty($client, 'stream'))->getValue($client), STREAM_SHUT_WR);
            $server->waitFor('pinged=');

            expect(websocket_messages($client, 1, 3.0))->toBe([])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('refuses a handshake that the server resets', function (): void {
        $server = ScriptedWebSocketServer::start('reset-handshake');

        try {
            $client = new StreamWebSocketClient;

            expect(fn () => $client->connect($server->endpoint(), 5.0))
                ->toThrow(WebSocketException::class, 'Reverb refused the WebSocket handshake.')
                ->and($client->isConnected())->toBeFalse()
                ->and($server->waitFor('reset='))->toBe('reset=yes');
        } finally {
            $server->stop();
        }
    });

    it('subscribes with a signed membership, fills the view, and reconnects after Reverb drops it', function (): void {
        [$websocketNode, $credentials] = activate_websocket_role(Node::query()->create([
            'name' => 'websocket-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'darwin',
            'public_ssh_host' => '192.0.2.90',
            'wireguard_ip' => '127.0.0.1',
            'user' => 'orbit',
        ]));
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.30',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:app-dev',
        ]);
        $server = ScriptedWebSocketServer::start('pusher', [$credentials->appSecret, (string) $node->id]);
        $view = app(AgentStateView::class);

        try {
            $subscriber = new AgentViewSubscriber(
                socket: new StreamWebSocketClient,
                credentials: app(WebSocketCredentialManager::class),
                view: app(CacheAgentStateView::class),
                signer: new PresenceChannelSigner,
                log: new NullLogger,
                caPath: $server->caPath,
                commit: static fn (): ?string => null,
                clock: static fn (): float => microtime(true),
                sleep: static function (float $seconds): void {
                    usleep((int) ($seconds * 1_000_000));
                },
                reverbPort: $server->port,
            );
            $pass = static function (Closure $until, float $seconds = 10.0) use ($subscriber): bool {
                $deadline = microtime(true) + $seconds;

                while (microtime(true) < $deadline) {
                    $subscriber->pass();

                    if ($until()) {
                        return true;
                    }
                }

                return false;
            };

            expect($pass(fn (): bool => $view->node($node->id)->isFresh()))->toBeTrue()
                ->and($server->waitFor('subscribe='))->toBe('subscribe=signed member=gateway.100.1')
                ->and($view->node($node->id)->status(ProcessRuntime::Systemd, 'orbit-process-7-web'))->toBe('active')
                ->and($view->node((int) $websocketNode->id)->freshness)->toBe(AgentViewFreshness::Missing);

            expect($pass(fn (): bool => ! $view->node($node->id)->isFresh()))->toBeTrue();

            expect($pass(fn (): bool => $view->node($node->id)->isFresh()))->toBeTrue();
            $server->waitFor('accepted=');
            $droppedAt = (float) substr($server->waitFor('dropped='), 8);
            $reconnectedAt = (float) substr($server->waitFor('accepted='), 9);

            // The first retry waits the one-second backoff plus jitter.
            expect($reconnectedAt - $droppedAt)->toBeGreaterThanOrEqual(1.0)->toBeLessThan(2.0)
                ->and($server->waitFor('subscribe='))->toBe('subscribe=signed member=gateway.200.2')
                ->and($view->subscriber()?->connected)->toBeTrue();
        } finally {
            $server->stop();
        }
    });
});
