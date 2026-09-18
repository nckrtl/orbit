<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use InvalidArgumentException;

/**
 * Publishes a leaf certificate to the Gateway's own Caddy, one managed pair per hostname the
 * Metrics role owns. `metrics.orbit` (Grafana) and `prometheus.orbit` (Prometheus) each get their
 * own instance, told apart only by `$slug`: it names the certificate and key files, the versions
 * and "current" directories, and the ownership marker a later publish or removal checks before
 * touching anything, so the two hostnames' certificates can never collide or be mistaken for one
 * another.
 */
final readonly class MetricsCertificatePublisher
{
    public function __construct(
        private ProcessRunner $processes,
        private string $slug = 'metrics',
    ) {}

    public function publish(GatewayCertificatePaths $certificate): MetricsPublicationReceipt
    {
        $this->validatePath($certificate->certificatePath);
        $this->validatePath($certificate->privateKeyPath);
        $version = bin2hex(random_bytes(8));
        $versions = $this->versionsDirectory();
        $current = $this->currentPath();
        $certificateFile = $this->certificateFilename();
        $keyFile = $this->keyFilename();
        $ownerMarker = $this->ownerMarker();
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
            input: <<<BASH
                version=\$1
                source_certificate=\$2
                source_key=\$3
                versions={$versions}
                current={$current}
                certificate_file={$certificateFile}
                key_file={$keyFile}
                owner_marker={$ownerMarker}
                owner="\$versions/.orbit-owner"
                directory="\$versions/\$version"
                candidate="\$directory.candidate"
                link="\$current.candidate"
                exec 9>/run/lock/orbit-caddy.lock
                flock -w 30 9
                trap 'rm -rf -- "\$candidate"; rm -f -- "\$link"' EXIT
                if [ -e "\$versions" ]; then
                    test -d "\$versions"
                    test -f "\$owner"
                    test "\$(cat -- "\$owner")" = "\$owner_marker"
                else
                    install -d -o root -g caddy -m 0750 -- "\$versions"
                    printf '%s\\n' "\$owner_marker" | install -o root -g caddy -m 0640 /dev/stdin "\$owner"
                fi
                previous_target=
                if [ -L "\$current" ]; then
                    current_target=\$(readlink -f "\$current")
                    case "\$current_target" in
                        "\$versions"/*) ;;
                        *) exit 1 ;;
                    esac
                    previous_target=\$(readlink "\$current")
                    if cmp -s -- "\$source_certificate" "\$current/\$certificate_file" && cmp -s -- "\$source_key" "\$current/\$key_file"; then
                        printf 'orbit-metrics-publication:unchanged\\n'
                        exit 0
                    fi
                elif [ -e "\$current" ]; then
                    exit 1
                fi
                install -d -o root -g caddy -m 0750 -- "\$candidate"
                install -o root -g caddy -m 0640 -- "\$source_certificate" "\$candidate/\$certificate_file"
                install -o root -g caddy -m 0640 -- "\$source_key" "\$candidate/\$key_file"
                certificate_public=\$(openssl x509 -in "\$candidate/\$certificate_file" -pubkey -noout)
                private_public=\$(openssl pkey -in "\$candidate/\$key_file" -pubout)
                test "\$certificate_public" = "\$private_public"
                mv -fT -- "\$candidate" "\$directory"
                ln -s -- "\$directory" "\$link"
                mv -fT -- "\$link" "\$current"
                if systemctl is-active --quiet caddy; then
                    if ! systemctl reload-or-restart caddy; then
                        if [ -n "\$previous_target" ]; then
                            ln -s -- "\$previous_target" "\$link"
                            mv -fT -- "\$link" "\$current"
                        else
                            rm -f -- "\$current"
                        fi
                        systemctl reload-or-restart caddy || true
                        rm -rf -- "\$directory"
                        exit 1
                    fi
                fi
                if [ -n "\$previous_target" ]; then
                    previous_encoded=\$(printf '%s' "\$previous_target" | base64 -w 0)
                    printf 'orbit-metrics-publication:replaced:%s\\n' "\$previous_encoded"
                else
                    printf 'orbit-metrics-publication:created\\n'
                fi
                BASH,
        ));

        if (! $result->succeeded()) {
            throw $this->publicationFailed();
        }

        try {
            return MetricsPublicationReceipt::fromProcessOutput($result->stdout);
        } catch (InvalidArgumentException) {
            throw $this->publicationFailed();
        }
    }

    public function restore(MetricsPublicationReceipt $receipt): void
    {
        if ($receipt->isUnchanged()) {
            return;
        }

        $previousTarget = $receipt->wasCreated() ? '' : $receipt->previousPublication();
        $this->validatePreviousTarget($previousTarget);
        $change = $receipt->wasCreated() ? 'created' : 'replaced';
        $versions = $this->versionsDirectory();
        $current = $this->currentPath();
        $ownerMarker = $this->ownerMarker();
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu', '--', $change, $previousTarget],
            timeout: 60.0,
            input: <<<BASH
                change=\$1
                previous_target=\$2
                versions={$versions}
                current={$current}
                owner_marker={$ownerMarker}
                owner="\$versions/.orbit-owner"
                link="\$current.rollback"
                exec 9>/run/lock/orbit-caddy.lock
                flock -w 30 9
                trap 'rm -f -- "\$link"' EXIT
                test -d "\$versions"
                test -f "\$owner"
                test "\$(cat -- "\$owner")" = "\$owner_marker"
                test -L "\$current"
                published_target=\$(readlink "\$current")
                published_resolved=\$(readlink -f "\$current")
                case "\$published_resolved" in
                    "\$versions"/*) ;;
                    *) exit 1 ;;
                esac
                case "\$change" in
                    created)
                        test -z "\$previous_target"
                        rm -f -- "\$current"
                        ;;
                    replaced)
                        test -n "\$previous_target"
                        previous_resolved=\$(readlink -f -- "\$previous_target")
                        case "\$previous_resolved" in
                            "\$versions"/*) ;;
                            *) exit 1 ;;
                        esac
                        test -d "\$previous_resolved"
                        ln -s -- "\$previous_target" "\$link"
                        mv -fT -- "\$link" "\$current"
                        ;;
                    *) exit 1 ;;
                esac
                if systemctl is-active --quiet caddy && ! systemctl reload-or-restart caddy; then
                    ln -s -- "\$published_target" "\$link"
                    mv -fT -- "\$link" "\$current"
                    systemctl reload-or-restart caddy || true
                    exit 1
                fi
                rm -rf -- "\$published_resolved"
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'metrics.certificate_restoration_failed',
                'Metrics certificate restoration did not complete.',
                502,
            );
        }
    }

    public function remove(): void
    {
        $versions = $this->versionsDirectory();
        $current = $this->currentPath();
        $ownerMarker = $this->ownerMarker();
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu'],
            timeout: 60.0,
            input: <<<BASH
                versions={$versions}
                current={$current}
                owner_marker={$ownerMarker}
                owner="\$versions/.orbit-owner"
                exec 9>/run/lock/orbit-caddy.lock
                flock -w 30 9
                if [ ! -e "\$versions" ] && [ ! -e "\$current" ] && [ ! -L "\$current" ]; then
                    exit 0
                fi
                test -d "\$versions"
                test -f "\$owner"
                test "\$(cat -- "\$owner")" = "\$owner_marker"
                if [ -L "\$current" ]; then
                    current_target=\$(readlink -f "\$current")
                    case "\$current_target" in
                        "\$versions"/*) ;;
                        *) exit 1 ;;
                    esac
                elif [ -e "\$current" ]; then
                    exit 1
                fi
                rm -f -- "\$current"
                rm -rf -- "\$versions"
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

    private function versionsDirectory(): string
    {
        return "/etc/caddy/orbit-{$this->slug}-cert-versions";
    }

    private function currentPath(): string
    {
        return "/etc/caddy/orbit-{$this->slug}-cert-current";
    }

    private function certificateFilename(): string
    {
        return "{$this->slug}.pem";
    }

    private function keyFilename(): string
    {
        return "{$this->slug}.key";
    }

    private function ownerMarker(): string
    {
        return "{$this->slug}-certificate";
    }

    private function publicationFailed(): ResourceOperationException
    {
        return new ResourceOperationException(
            'metrics.certificate_publication_failed',
            'Metrics certificate publication did not complete.',
            502,
        );
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

    private function validatePreviousTarget(string $target): void
    {
        if ($target === '') {
            return;
        }

        if (
            str_contains($target, "\0")
            || preg_match('/[\r\n]/', $target) === 1
            || ! str_starts_with($target, $this->versionsDirectory().'/')
        ) {
            throw new InvalidArgumentException('A previous Metrics certificate target is invalid.');
        }
    }
}
