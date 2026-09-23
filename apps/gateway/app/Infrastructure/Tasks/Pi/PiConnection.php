<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Models\Node;
use SensitiveParameter;

/**
 * Resolves a Node's Pi server address and token. Node settings under `pi` take precedence;
 * a Node with `pi` settings never falls back to the Gateway-wide token.
 */
final readonly class PiConnection
{
    public function baseUrl(Node $node): string
    {
        $url = $this->settings($node)['url'];
        if ($url !== null) {
            return rtrim($url, '/');
        }
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new AgentDriverException('The agent Node address is unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = (int) config('orbit.pi.port', 3774);

        return 'http://'.$host.':'.($port > 0 && $port <= 65535 ? $port : 3774);
    }

    public function token(Node $node): string
    {
        return $this->settings($node)['token'] ?? throw new AgentDriverException('The Node has no Pi server token configured.');
    }

    /** @return array{token: string|null, url: string|null} */
    private function settings(Node $node): array
    {
        $settings = $node->settings;
        if (! is_array($settings) || ! array_key_exists('pi', $settings)) {
            return ['token' => $this->string(config('orbit.pi.token')), 'url' => null];
        }
        $pi = $settings['pi'];
        if (! is_array($pi)) {
            return ['token' => null, 'url' => null];
        }

        return ['token' => $this->string($pi['token'] ?? null), 'url' => $this->string($pi['url'] ?? null)];
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
