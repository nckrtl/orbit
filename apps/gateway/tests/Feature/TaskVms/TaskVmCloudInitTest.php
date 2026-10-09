<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmCloudInit;

it('renders only the orbit user, the Gateway key and openssh-server', function (): void {
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

    expect(new TaskVmCloudInit()->render($key))->toBe(<<<'YAML'
        #cloud-config
        {
            "users": [
                {
                    "name": "orbit",
                    "lock_passwd": true,
                    "shell": "/bin/bash",
                    "sudo": "ALL=(ALL) NOPASSWD:ALL",
                    "ssh_authorized_keys": [
                        "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway"
                    ]
                }
            ],
            "ssh_pwauth": false,
            "disable_root": true,
            "package_update": true,
            "packages": [
                "openssh-server"
            ]
        }

        YAML);
});

it('refuses a Gateway key that is not one public key line', function (string $key): void {
    expect(fn (): string => new TaskVmCloudInit()->render($key))
        ->toThrow(TaskVmException::class, 'The Gateway SSH public key is not a single OpenSSH public key line.');
})->with([
    'empty' => [''],
    'private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
    'two lines' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOther"],
    'trailing newline' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\n"],
    'options prefix' => ['command="sh" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake'],
]);
