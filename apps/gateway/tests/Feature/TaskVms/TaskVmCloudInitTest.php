<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmCloudInit;

it('renders only the orbit user, the Gateway key, openssh-server and Chromium\'s libraries', function (): void {
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
            "package_update": true,
            "packages": [
                "openssh-server",
                "libasound2t64",
                "libatk-bridge2.0-0t64",
                "libatk1.0-0t64",
                "libatspi2.0-0t64",
                "libcairo2",
                "libcups2t64",
                "libdbus-1-3",
                "libdrm2",
                "libgbm1",
                "libglib2.0-0t64",
                "libnspr4",
                "libnss3",
                "libpango-1.0-0",
                "libx11-6",
                "libxcb1",
                "libxcomposite1",
                "libxdamage1",
                "libxext6",
                "libxfixes3",
                "libxkbcommon0",
                "libxrandr2"
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
