<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

/** @mago-expect lint:excessive-parameter-list */
final readonly class NodeResponse
{
    /** @param list<string> $roles */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public string $publicSshHost,
        public int $publicSshPort,
        public string $sshUser,
        public ?string $wireguardAddress,
        public array $roles,
        public string $requestId,
    ) {}

    /** @return array<string, int|string|list<string>|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'public_ssh_host' => $this->publicSshHost,
            'public_ssh_port' => $this->publicSshPort,
            'ssh_user' => $this->sshUser,
            'wireguard_address' => $this->wireguardAddress,
            'roles' => $this->roles,
            'request_id' => $this->requestId,
        ];
    }
}
