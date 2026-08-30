<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use InvalidArgumentException;

final readonly class MetricsCertificatePublisher
{
    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function publish(GatewayCertificatePaths $certificate): bool
    {
        $this->validatePath($certificate->certificatePath);
        $this->validatePath($certificate->privateKeyPath);
        $version = bin2hex(random_bytes(8));
        $result = $this->processes->run(new ProcessInvocation(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $version,
                $certificate->certificatePath,
                $certificate->privateKeyPath,
            ],
            timeout: 60.0,
            input: <<<'BASH'
                version=$1
                source_certificate=$2
                source_key=$3
                versions=/etc/caddy/orbit-metrics-cert-versions
                owner="$versions/.orbit-owner"
                directory="$versions/$version"
                candidate="$directory.candidate"
                link=/etc/caddy/orbit-metrics-cert-current.candidate
                current=/etc/caddy/orbit-metrics-cert-current
                exec 9>/run/lock/orbit-caddy.lock
                flock -w 30 9
                trap 'rm -rf -- "$candidate"; rm -f -- "$link"' EXIT
                if [ -e "$versions" ]; then
                    test -d "$versions"
                    test -f "$owner"
                    test "$(cat -- "$owner")" = metrics-certificate
                else
                    install -d -o root -g caddy -m 0750 -- "$versions"
                    printf 'metrics-certificate\n' | install -o root -g caddy -m 0640 /dev/stdin "$owner"
                fi
                if [ -L "$current" ]; then
                    current_target=$(readlink -f "$current")
                    case "$current_target" in
                        "$versions"/*) ;;
                        *) exit 1 ;;
                    esac
                    if cmp -s -- "$source_certificate" "$current/metrics.pem" && cmp -s -- "$source_key" "$current/metrics.key"; then
                        exit 0
                    fi
                elif [ -e "$current" ]; then
                    exit 1
                fi
                install -d -o root -g caddy -m 0750 -- "$candidate"
                install -o root -g caddy -m 0640 -- "$source_certificate" "$candidate/metrics.pem"
                install -o root -g caddy -m 0640 -- "$source_key" "$candidate/metrics.key"
                certificate_public=$(openssl x509 -in "$candidate/metrics.pem" -pubkey -noout)
                private_public=$(openssl pkey -in "$candidate/metrics.key" -pubout)
                test "$certificate_public" = "$private_public"
                mv -fT -- "$candidate" "$directory"
                ln -s -- "$directory" "$link"
                mv -fT -- "$link" "$current"
                if systemctl is-active --quiet caddy; then
                    systemctl reload-or-restart caddy
                fi
                printf 'changed\n'
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'metrics.certificate_publication_failed',
                'Metrics certificate publication did not complete.',
                502,
            );
        }

        return trim($result->stdout) === 'changed';
    }

    public function remove(): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu'],
            timeout: 60.0,
            input: <<<'BASH'
                versions=/etc/caddy/orbit-metrics-cert-versions
                current=/etc/caddy/orbit-metrics-cert-current
                owner="$versions/.orbit-owner"
                exec 9>/run/lock/orbit-caddy.lock
                flock -w 30 9
                if [ ! -e "$versions" ] && [ ! -e "$current" ] && [ ! -L "$current" ]; then
                    exit 0
                fi
                test -d "$versions"
                test -f "$owner"
                test "$(cat -- "$owner")" = metrics-certificate
                if [ -L "$current" ]; then
                    current_target=$(readlink -f "$current")
                    case "$current_target" in
                        "$versions"/*) ;;
                        *) exit 1 ;;
                    esac
                elif [ -e "$current" ]; then
                    exit 1
                fi
                rm -f -- "$current"
                rm -rf -- "$versions"
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'metrics.certificate_removal_failed',
                'Metrics certificate removal did not complete.',
                502,
            );
        }
    }

    private function validatePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/[\r\n]/', $path) === 1
            || ! str_starts_with($path, '/')
        ) {
            throw new InvalidArgumentException('Metrics certificate source paths must be absolute paths.');
        }
    }
}
