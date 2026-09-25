<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use SensitiveParameter;

/**
 * The one Reverb application identity the Gateway and the websocket role's
 * node must agree on: an app id, a public key, a secret, and the Laravel
 * `APP_KEY` the Reverb app itself needs to boot. Generated once per role
 * assignment and kept stable across converges.
 */
final readonly class WebSocketCredentials
{
    public function __construct(
        public string $appId,
        public string $appKey,
        #[SensitiveParameter]
        public string $appSecret,
        #[SensitiveParameter]
        public string $laravelAppKey,
        /** WireGuard address of the node that serves Reverb, when read from an active assignment. */
        public ?string $servingAddress = null,
        /**
         * Every address whose Reverb holds clients, the serving address first. During a `websocket` move it
         * also names the old Node until that Node withdraws, so the Gateway publishes to and listens on both.
         *
         * @var list<string>
         */
        public array $servingAddresses = [],
    ) {}

    /** @return list<string> */
    public function addresses(): array
    {
        if ($this->servingAddresses !== []) {
            return $this->servingAddresses;
        }

        return $this->servingAddress === null || $this->servingAddress === '' ? [] : [$this->servingAddress];
    }

    public function __debugInfo(): array
    {
        return [
            'appId' => $this->appId,
            'appKey' => $this->appKey,
            'appSecret' => '[PROTECTED]',
            'laravelAppKey' => '[PROTECTED]',
        ];
    }
}
