<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmCloudInit;

const CLOUD_INIT_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';

it('renders only the orbit user and the Gateway key for a task VM, which boots from the base image', function (): void {
    expect(new TaskVmCloudInit()->render(CLOUD_INIT_KEY))->toBe(<<<'YAML'
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
            ]
        }

        YAML);
});

it('adds a package upgrade, openssh-server and Chromium\'s libraries for the base image builder', function (): void {
    expect(new TaskVmCloudInit()->renderImageBuilder(CLOUD_INIT_KEY))->toBe(<<<'YAML'
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
            "package_upgrade": true,
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
        ->toThrow(TaskVmException::class, 'The Gateway SSH public key is not a single OpenSSH public key line.')
        ->and(fn (): string => new TaskVmCloudInit()->renderImageBuilder($key))
        ->toThrow(TaskVmException::class, 'The Gateway SSH public key is not a single OpenSSH public key line.');
})->with([
    'empty' => [''],
    'private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
    'two lines' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOther"],
    'trailing newline' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake orbit@gateway\n"],
    'options prefix' => ['command="sh" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake'],
]);
