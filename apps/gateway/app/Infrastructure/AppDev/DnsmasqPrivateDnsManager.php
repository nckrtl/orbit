<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

final readonly class DnsmasqPrivateDnsManager implements PrivateDnsManager
{
    public function __construct(
        private ProcessRunner $processes,
        private AppDevDnsConfigRenderer $renderer,
        private ?DevelopmentProjectionOperationLock $projection = null,
    ) {}

    public function converge(?Node $pendingNode = null): void
    {
        $this->owner()->run(fn () => $this->publish($pendingNode, null, null));
    }

    public function convergeRoute(Route $route): void
    {
        $this->owner()->run(fn () => $this->publish(null, $route, null));
    }

    public function convergeUnavailableRoute(Route $route, AppInstance $appInstance): void
    {
        $this->owner()->run(fn () => $this->publish(null, $route, $appInstance));
    }

    private function publish(
        ?Node $pendingNode,
        ?Route $pendingRoute,
        ?AppInstance $unavailableInstance,
    ): void {
        $configuration = $this->renderer->render($pendingNode, $pendingRoute, $unavailableInstance);
        $encoded = base64_encode($configuration);
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu'],
            timeout: 60.0,
            input: <<<BASH
                managed=/etc/dnsmasq.d/orbit-records.conf
                candidate=/etc/dnsmasq.d/.orbit-records.\$\$.candidate
                validation=\$(mktemp -d)
                backup=\$(mktemp /etc/dnsmasq.d/.orbit-records.backup.XXXXXX)
                had_managed=0
                trap 'rm -rf -- "\$validation"; rm -f -- "\$candidate" "\$backup"' EXIT
                exec 9>/run/lock/orbit-dnsmasq.lock
                flock -w 30 9
                if [ -f "\$managed" ]; then
                    cp --preserve=mode,ownership -- "\$managed" "\$backup"
                    had_managed=1
                fi
                install -d -m 0755 -- "\$validation/fragments"
                cp -a -- /etc/dnsmasq.d/. "\$validation/fragments/"
                printf '%s' '{$encoded}' | base64 --decode > "\$validation/fragments/orbit-records.conf"
                sed "s#/etc/dnsmasq.d#\$validation/fragments#g" /etc/dnsmasq.conf > "\$validation/dnsmasq.conf"
                dnsmasq --test --conf-file="\$validation/dnsmasq.conf"
                if [ -f "\$managed" ] && cmp -s -- "\$validation/fragments/orbit-records.conf" "\$managed"; then
                    if systemctl is-active --quiet dnsmasq; then
                        exit 0
                    fi
                    systemctl restart dnsmasq
                    exit 0
                fi
                install -o root -g root -m 0644 -- "\$validation/fragments/orbit-records.conf" "\$candidate"
                mv -fT -- "\$candidate" "\$managed"
                if ! systemctl restart dnsmasq; then
                    if [ "\$had_managed" = 1 ]; then
                        install -o root -g root -m 0644 -- "\$backup" "\$managed"
                    else
                        rm -f -- "\$managed"
                    fi
                    systemctl restart dnsmasq || true
                    exit 1
                fi
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new RuntimeConvergenceException(
                step: 'private-dns',
                errorCode: 'app-dev.dns_config_failed',
                message: 'Could not converge Orbit private DNS records.',
                result: $result,
            );
        }
    }

    private function owner(): DevelopmentProjectionOperationLock
    {
        return $this->projection ?? app(DevelopmentProjectionOperationLock::class);
    }
}
