<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate on the proxycli Process Node. */
final readonly class ProxyCliCertificatePublisher
{
    public function command(
        string $certificatePem,
        #[SensitiveParameter] string $privateKeyPem,
    ): RemoteCommand {
        $certificateEncoded = base64_encode($certificatePem);
        $keyEncoded = base64_encode($privateKeyPem);
        $current = ProxyCliFootprint::CertificateCurrentDirectory;
        $caddyService = ProxyCliFootprint::CaddyServiceName;
        $lock = ProxyCliFootprint::CaddyLockPath;

        $script = <<<BASH
            current={$current}
            caddy_service={$caddyService}
            lock={$lock}
            exec 9>"\$lock"
            flock -w 30 9
            candidate="\$current.orbit-candidate"
            trap 'rm -rf -- "\$candidate"' EXIT
            install -d -o root -g caddy -m 0750 -- "\$candidate"
            printf '%s' '{$certificateEncoded}' | base64 --decode > "\$candidate/proxycli.pem"
            printf '%s' '{$keyEncoded}' | base64 --decode > "\$candidate/proxycli.key"
            chown root:caddy "\$candidate/proxycli.pem" "\$candidate/proxycli.key"
            chmod 0640 "\$candidate/proxycli.pem" "\$candidate/proxycli.key"
            certificate_public=\$(openssl x509 -in "\$candidate/proxycli.pem" -pubkey -noout)
            private_public=\$(openssl pkey -in "\$candidate/proxycli.key" -pubout)
            test "\$certificate_public" = "\$private_public"
            if [ -d "\$current" ] \\
                && cmp -s -- "\$candidate/proxycli.pem" "\$current/proxycli.pem" \\
                && cmp -s -- "\$candidate/proxycli.key" "\$current/proxycli.key"; then
                rm -rf -- "\$candidate"
                exit 0
            fi
            rm -rf -- "\$current"
            mv -fT -- "\$candidate" "\$current"
            if systemctl is-active --quiet "\$caddy_service"; then
                systemctl reload-or-restart "\$caddy_service"
            fi
            BASH;

        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu'],
            protectedInput: ProtectedInput::fromString($script),
        );
    }

    public function removeCommand(): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', ProxyCliFootprint::CertificateCurrentDirectory],
        );
    }
}
