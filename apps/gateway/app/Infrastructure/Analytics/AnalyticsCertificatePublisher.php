<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use SensitiveParameter;

/** Publishes and removes the Orbit CA leaf certificate on the analytics role's node. */
final readonly class AnalyticsCertificatePublisher
{
    public function command(
        string $certificatePem,
        #[SensitiveParameter] string $privateKeyPem,
    ): RemoteCommand {
        $certificateEncoded = base64_encode($certificatePem);
        $keyEncoded = base64_encode($privateKeyPem);
        $current = AnalyticsFootprint::CertificateCurrentDirectory;
        $caddyService = AnalyticsFootprint::CaddyServiceName;
        $lock = AnalyticsFootprint::CaddyLockPath;

        $script = <<<BASH
            current={$current}
            caddy_service={$caddyService}
            lock={$lock}
            exec 9>"\$lock"
            flock -w 30 9
            candidate="\$current.orbit-candidate"
            trap 'rm -rf -- "\$candidate"' EXIT
            install -d -o root -g caddy -m 0750 -- "\$candidate"
            printf '%s' '{$certificateEncoded}' | base64 --decode > "\$candidate/analytics.pem"
            printf '%s' '{$keyEncoded}' | base64 --decode > "\$candidate/analytics.key"
            chown root:caddy "\$candidate/analytics.pem" "\$candidate/analytics.key"
            chmod 0640 "\$candidate/analytics.pem" "\$candidate/analytics.key"
            certificate_public=\$(openssl x509 -in "\$candidate/analytics.pem" -pubkey -noout)
            private_public=\$(openssl pkey -in "\$candidate/analytics.key" -pubout)
            test "\$certificate_public" = "\$private_public"
            if [ -d "\$current" ] \\
                && cmp -s -- "\$candidate/analytics.pem" "\$current/analytics.pem" \\
                && cmp -s -- "\$candidate/analytics.key" "\$current/analytics.key"; then
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
        $current = AnalyticsFootprint::CertificateCurrentDirectory;

        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', $current],
        );
    }
}
