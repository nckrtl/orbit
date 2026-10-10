<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;

/**
 * The cloud-init user-data of task VMs: the `orbit` user with passwordless sudo and the Gateway's
 * SSH key. A task VM boots from the host's base image, which already holds everything else, so its
 * user-data installs nothing. The base image builder gets the same user plus a package upgrade,
 * `openssh-server`, which the stock linuxcontainers Ubuntu cloud image lacks, and Chromium's system
 * libraries, so a Project's Playwright browser tests run.
 *
 * It sets no SSH option such as `ssh_pwauth`: on that image cloud-init applies it before
 * `openssh-server` exists, writes a one-line `sshd_config` with `UsePAM no`, and sshd then
 * refuses the passwordless `orbit` user as locked.
 */
final readonly class TaskVmCloudInit
{
    private const string PublicKeyPattern = '/\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/]+={0,3}(?: [!-~][ -~]*)?\z/D';

    /**
     * Playwright's `chromium` dependency list for `ubuntu26.04-x64` (`nativeDeps.ts`), which
     * covers both `chromium` and `chromium-headless-shell`. Its separate `tools` list (xvfb and
     * fonts) is for headed runs and is left out.
     *
     * @var list<string>
     */
    private const array ChromiumLibraries = [
        'libasound2t64', 'libatk-bridge2.0-0t64', 'libatk1.0-0t64', 'libatspi2.0-0t64', 'libcairo2',
        'libcups2t64', 'libdbus-1-3', 'libdrm2', 'libgbm1', 'libglib2.0-0t64', 'libnspr4', 'libnss3',
        'libpango-1.0-0', 'libx11-6', 'libxcb1', 'libxcomposite1', 'libxdamage1', 'libxext6',
        'libxfixes3', 'libxkbcommon0', 'libxrandr2',
    ];

    /** The user-data of a task VM, which boots from the base image. */
    public function render(string $gatewayPublicKey): string
    {
        return self::document(['users' => [$this->user($gatewayPublicKey)]]);
    }

    /** The user-data of the base image builder, which boots from the stock image. */
    public function renderImageBuilder(string $gatewayPublicKey): string
    {
        return self::document([
            'users' => [$this->user($gatewayPublicKey)],
            'package_update' => true,
            'package_upgrade' => true,
            'packages' => ['openssh-server', ...self::ChromiumLibraries],
        ]);
    }

    /** @return array<string, mixed> */
    private function user(string $gatewayPublicKey): array
    {
        if (preg_match(self::PublicKeyPattern, $gatewayPublicKey) !== 1) {
            throw new TaskVmException('task_vm.invalid_gateway_key', 'The Gateway SSH public key is not a single OpenSSH public key line.', 500);
        }

        return [
            'name' => 'orbit',
            'lock_passwd' => true,
            'shell' => '/bin/bash',
            'sudo' => 'ALL=(ALL) NOPASSWD:ALL',
            'ssh_authorized_keys' => [$gatewayPublicKey],
        ];
    }

    /** @param  array<string, mixed>  $config */
    private static function document(array $config): string
    {
        return "#cloud-config\n".json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }
}
