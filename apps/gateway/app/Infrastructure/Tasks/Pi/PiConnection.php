<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;
use App\Models\TaskVm;
use SensitiveParameter;

/**
 * Resolves a Node's Pi server address and token. A task VM Node uses its own token. Otherwise Node
 * settings under `pi` take precedence; a Node with `pi` settings never falls back to the Gateway-wide token.
 */
final readonly class PiConnection
{
    public function baseUrl(Node|PiEndpoint $node): string
    {
        if ($node instanceof PiEndpoint) {
            return $node->url;
        }
        $url = $this->settings($node)['url'];
        if ($url !== null) {
            return rtrim($url, '/');
        }
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new AgentDriverException('The agent Node address is unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;

        return 'http://'.$host.':'.$this->port();
    }

    /** The port of every managed Pi server, `ORBIT_PI_PORT`. */
    public function port(): int
    {
        $port = StoredValue::integer(config('orbit.pi.port', 3774), 3774);

        return $port > 0 && $port <= 65535 ? $port : 3774;
    }

    public function token(Node|PiEndpoint $node): string
    {
        if ($node instanceof PiEndpoint) {
            return $node->token();
        }
        $vm = TaskVmPlacement::forNode($node);
        if ($vm instanceof TaskVm) {
            return $vm->pi_token;
        }

        return $this->settings($node)['token'] ?? throw new AgentDriverException('The Node has no Pi server token configured.');
    }

    /**
     * Values that never leave the driver in a transcript: the token, and a task VM's model key.
     *
     * @return list<string>
     */
    public function secrets(Node|PiEndpoint $node): array
    {
        if ($node instanceof PiEndpoint) {
            return $node->secrets();
        }
        $vm = TaskVmPlacement::forNode($node);
        if ($vm instanceof TaskVm) {
            return array_values(array_filter([$vm->pi_token, $vm->model_key ?? ''], static fn (string $value): bool => $value !== ''));
        }

        return [$this->token($node)];
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
