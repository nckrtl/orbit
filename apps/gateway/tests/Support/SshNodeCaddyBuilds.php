<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Gateway\GatewayServingHost;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use LogicException;

/**
 * A real Node Caddy builder whose push goes through a test's fake SSH executor, so a test sees the whole
 * rendered Caddyfile each publisher's build pushes. A build that would run through local `sudo` fails.
 */
final class SshNodeCaddyBuilds
{
    public static function over(SshExecutor $ssh): NodeCaddyBuilder
    {
        return new NodeCaddyBuilder(
            app(NodeCaddyfileRenderer::class),
            new NodeCaddyBuildLock(sys_get_temp_dir().'/orbit-test-caddy-build-'.getmypid()),
            new NodeCaddyTransport(
                new class implements ProcessRunner
                {
                    public function run(ProcessInvocation $invocation): CommandResult
                    {
                        throw new LogicException('A test build must not run through local sudo.');
                    }
                },
                $ssh,
                new class implements SshKeyProvider
                {
                    public function privateKeyPath(): string
                    {
                        return '/tmp/orbit-test-key';
                    }

                    public function publicKey(): string
                    {
                        return 'ssh-ed25519 AAAA test';
                    }
                },
                new class implements KnownHostsStore
                {
                    public function path(): string
                    {
                        return '/tmp/orbit-test-known-hosts';
                    }

                    public function put(string $host, int $port, HostKey $key): void {}
                },
                app(GatewayServingHost::class),
            ),
        );
    }

    /** The Caddyfile a push command carries, or null for any other command. */
    public static function pushed(RemoteCommand $command): ?string
    {
        if (preg_match("/printf '%s' '([A-Za-z0-9+\\/=]+)' \\| base64 --decode > \"\\\$candidate\\/Caddyfile\"/", (string) $command->input, $match) !== 1) {
            return null;
        }

        $content = base64_decode($match[1], true);

        return is_string($content) && str_starts_with($content, NodeCaddyfileRenderer::Marker) ? $content : null;
    }
}
