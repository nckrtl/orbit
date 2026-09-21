<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Models\Node;
use SensitiveParameter;

final readonly class T3Connection
{
    public function baseUrl(Node $node): string
    {
        $credentials = $this->credentials($node);
        if ($credentials['base_url'] !== null) {
            return rtrim($credentials['base_url'], '/');
        }
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new T3DispatchException('The agent Node address is unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = (int) config('orbit.t3.port', 3773);

        return 'http://'.$host.':'.($port > 0 && $port <= 65535 ? $port : 3773);
    }

    /**
     * @return array{token: string|null, base_url: string|null}
     */
    public function credentials(Node $node): array
    {
        $settings = $node->settings;
        $t3 = is_array($settings) && array_key_exists('t3', $settings) ? $settings['t3'] : null;

        if ($t3 !== null) {
            if (! is_array($t3)) {
                throw new T3DispatchException('The Node has no T3 token configured.');
            }

            $token = $this->string($t3['token'] ?? null);

            if ($token === null) {
                throw new T3DispatchException('The Node has no T3 token configured.');
            }

            return [
                'token' => $token,
                'base_url' => $this->string($t3['url'] ?? $t3['base_url'] ?? null),
            ];
        }

        return [
            'token' => $this->string(config('orbit.t3.token')),
            'base_url' => null,
        ];
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
