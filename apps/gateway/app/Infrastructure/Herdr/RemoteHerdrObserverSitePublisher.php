<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\HerdrSession;
use App\Models\Node;
use OpenSSLAsymmetricKey;
use RuntimeException;

final readonly class RemoteHerdrObserverSitePublisher implements HerdrObserverSitePublisher
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private LeafCertificateSigner $certificates,
    ) {}

    public function publish(HerdrSession $session, Node $node, string $caddyConfiguration): void
    {
        [$certificate, $key] = $this->leaf($session->observer_hostname);
        $this->ssh->execute(
            $node,
            $this->command(
                $session,
                $caddyConfiguration,
                $certificate,
                $key,
                retract: false,
            ),
            step: 'herdr-observer',
            errorCode: 'herdr.observer_failed',
        );
    }

    public function retract(HerdrSession $session, Node $node): void
    {
        $this->ssh->execute(
            $node,
            $this->command($session, '', '', '', retract: true),
            step: 'herdr-observer',
            errorCode: 'herdr.observer_failed',
        );
    }

    /** @return array{0: string, 1: string} */
    private function leaf(string $hostname): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create a Herdr observer certificate key.');
        }

        $csr = openssl_csr_new(['commonName' => $hostname], $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Could not create a Herdr observer certificate request.');
        }

        $csrPem = '';

        if (! openssl_csr_export($csr, $csrPem)) {
            throw new RuntimeException('Could not export a Herdr observer certificate request.');
        }

        $keyPem = '';

        if (! openssl_pkey_export($privateKey, $keyPem)) {
            throw new RuntimeException('Could not export a Herdr observer certificate key.');
        }

        return [$this->certificates->sign($hostname, $csrPem), $keyPem];
    }

    private function command(
        HerdrSession $session,
        string $caddyConfiguration,
        string $certificate,
        string $key,
        bool $retract,
    ): RemoteCommand {
        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $session->session,
                $retract ? 'retract' : 'publish',
            ],
            input: <<<BASH
                session=\$1
                action=\$2
                directory=/etc/orbit/herdr/\$session
                fragment=/etc/caddy/orbit-versions/current/fragments/herdr-\$session.caddy
                if [ "\$action" = retract ]; then
                    rm -rf -- "\$directory"
                    rm -f -- "\$fragment"
                    if command -v caddy >/dev/null && [ -f /etc/caddy/Caddyfile ]; then
                        caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
                    fi
                    exit 0
                fi
                install -d -o {$session->user} -g {$session->user} -m 0750 -- "\$directory"
                printf '%s' '{$this->encode($certificate)}' | base64 --decode > "\$directory/cert.pem"
                printf '%s' '{$this->encode($key)}' | base64 --decode > "\$directory/key.pem"
                chown {$session->user}:{$session->user} -- "\$directory/cert.pem" "\$directory/key.pem"
                chmod 0644 -- "\$directory/cert.pem"
                chmod 0600 -- "\$directory/key.pem"
                install -d -m 0750 -- /etc/caddy/orbit-versions/current/fragments
                printf '%s' '{$this->encode($caddyConfiguration)}' | base64 --decode > "\$fragment"
                if ! command -v caddy >/dev/null; then
                    printf 'caddy is not installed\n' >&2
                    exit 1
                fi
                caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
                BASH,
        );
    }

    private function encode(string $value): string
    {
        return base64_encode($value);
    }
}
