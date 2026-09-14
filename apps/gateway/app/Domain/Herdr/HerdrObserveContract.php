<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use App\Models\Node;

final readonly class HerdrObserveContract
{
    public const string Executable = '/home/linuxbrew/.linuxbrew/bin/herdr';

    public const string GrantAudience = 'herdr-observe';

    public const string GrantIssuer = 'orbit-gateway';

    public const string GrantScope = 'terminal.observe';

    public const int DefaultListenPort = 7411;

    public const int GrantTtlSeconds = 60;

    public const int DefaultProtocol = 22;

    public function assertCompatible(HerdrSessionInspection $inspection): void
    {
        if ($inspection->protocol === self::DefaultProtocol) {
            return;
        }

        $protocol = $inspection->protocol ?? 'unknown';

        throw new ResourceOperationException(
            errorCode: 'herdr.observer_unsupported',
            message: "Herdr observe protocol [{$protocol}] is not supported. Expected protocol [".self::DefaultProtocol.'].',
            status: 422,
        );
    }

    public function processName(string $session): string
    {
        return 'herdr-'.$session;
    }

    public function hostname(Node $node, string $session): string
    {
        $tld = $node->tld !== null && $node->tld !== '' ? rtrim($node->tld, '.') : 'orbit';

        return "{$session}.herdr.{$node->name}.{$tld}";
    }

    public function observerUrl(string $hostname): string
    {
        return 'wss://'.$hostname;
    }

    /**
     * @return list<string>
     */
    public function serverCommand(string $session): array
    {
        return [
            self::Executable,
            '--session',
            $session,
            'server',
        ];
    }

    public function listenAddress(int $port): string
    {
        return '127.0.0.1:'.$port;
    }

    public function nextPort(Node $node): int
    {
        $used = HerdrSession::query()
            ->where('node_id', $node->id)
            ->pluck('observer_port')
            ->all();

        $port = self::DefaultListenPort;

        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }
}
