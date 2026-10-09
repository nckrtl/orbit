<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;

/**
 * The cloud-init user-data for every task VM on every provider: the `orbit` user with
 * passwordless sudo and the Gateway's SSH key, plus `openssh-server`, which the stock
 * linuxcontainers Ubuntu cloud image lacks. Normal Node enrollment does everything else.
 *
 * It sets no SSH option such as `ssh_pwauth`: on that image cloud-init applies it before
 * `openssh-server` exists, writes a one-line `sshd_config` with `UsePAM no`, and sshd then
 * refuses the passwordless `orbit` user as locked.
 */
final readonly class TaskVmCloudInit
{
    private const string PublicKeyPattern = '/\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/]+={0,3}(?: [!-~][ -~]*)?\z/D';

    public function render(string $gatewayPublicKey): string
    {
        if (preg_match(self::PublicKeyPattern, $gatewayPublicKey) !== 1) {
            throw new TaskVmException('task_vm.invalid_gateway_key', 'The Gateway SSH public key is not a single OpenSSH public key line.', 500);
        }

        return "#cloud-config\n".json_encode([
            'users' => [[
                'name' => 'orbit',
                'lock_passwd' => true,
                'shell' => '/bin/bash',
                'sudo' => 'ALL=(ALL) NOPASSWD:ALL',
                'ssh_authorized_keys' => [$gatewayPublicKey],
            ]],
            'package_update' => true,
            'packages' => ['openssh-server'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }
}
