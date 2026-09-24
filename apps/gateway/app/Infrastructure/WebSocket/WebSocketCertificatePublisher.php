<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate on the websocket role's node. */
final readonly class WebSocketCertificatePublisher
{
    public function command(
        string $certificatePem,
        #[SensitiveParameter] string $privateKeyPem,
    ): RemoteCommand {
        $certificateEncoded = base64_encode($certificatePem);
        $keyEncoded = base64_encode($privateKeyPem);
        $current = WebSocketFootprint::CertificateCurrentDirectory;
        $caddyService = WebSocketFootprint::CaddyServiceName;
        $lockScript = CaddyPublicationLock::script(CaddyPublicationLock::Path);

        $script = <<<BASH
            current={$current}
            caddy_service={$caddyService}
            {$lockScript}
            candidate="\$current.orbit-candidate"
            trap 'rm -rf -- "\$candidate"' EXIT
            install -d -o root -g caddy -m 0750 -- "\$candidate"
            printf '%s' '{$certificateEncoded}' | base64 --decode > "\$candidate/reverb.pem"
            printf '%s' '{$keyEncoded}' | base64 --decode > "\$candidate/reverb.key"
            chown root:caddy "\$candidate/reverb.pem" "\$candidate/reverb.key"
            chmod 0640 "\$candidate/reverb.pem" "\$candidate/reverb.key"
            certificate_public=\$(openssl x509 -in "\$candidate/reverb.pem" -pubkey -noout)
            private_public=\$(openssl pkey -in "\$candidate/reverb.key" -pubout)
            test "\$certificate_public" = "\$private_public"
            if [ -d "\$current" ] \\
                && cmp -s -- "\$candidate/reverb.pem" "\$current/reverb.pem" \\
                && cmp -s -- "\$candidate/reverb.key" "\$current/reverb.key"; then
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
        $current = WebSocketFootprint::CertificateCurrentDirectory;

        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', $current],
        );
    }
}
