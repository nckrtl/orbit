<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

describe('GatewayPrivateDnsResolver', function (): void {
    it('reapplies a suffix-only route to VPN DNS whenever the tunnel starts', function (): void {
        expect(new GatewayPrivateDnsResolver()->dropIn('10.44.0.1', 'orbit'))->toBe(implode("\n", [
            '# Managed by Orbit.',
            '[Service]',
            "ExecStartPost=-/bin/sh -c 'test -e /etc/wireguard/orbit.dns-link || { resolvectl domain orbit \"~orbit\" && resolvectl default-route orbit false && resolvectl dns orbit 10.44.0.1; }'",
            '',
        ]));
    });

    it('sets the routing domain and no default route before the server, without tilde expansion', function (): void {
        $directory = sys_get_temp_dir().'/orbit-gateway-dns-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $log = "{$directory}/calls";
        file_put_contents("{$directory}/resolvectl", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$log}'\n");
        chmod("{$directory}/resolvectl", 0o755);

        try {
            // `root` names a user on every host, so an unquoted `~root` would expand to its home.
            $line = explode("\n", new GatewayPrivateDnsResolver()->dropIn('10.44.0.1', 'root'))[2];
            $script = Str::beforeLast(Str::after($line, "-c '"), "'");
            $process = new Process(['/bin/sh', '-c', $script], env: ['PATH' => "{$directory}:/usr/bin:/bin"]);
            $process->mustRun();

            expect(file($log, FILE_IGNORE_NEW_LINES))->toBe([
                'domain orbit ~root',
                'default-route orbit false',
                'dns orbit 10.44.0.1',
            ]);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('installs the drop-in and applies the route to the live orbit link', function (): void {
        $resolver = new GatewayPrivateDnsResolver;
        $command = $resolver->convergeCommand('10.44.0.1', 'orbit');
        $encoded = base64_encode($resolver->dropIn('10.44.0.1', 'orbit'));

        expect($command->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
            ->and($command->input)->toContain(
                'exec 9>/run/lock/orbit-wireguard-peer.lock',
                "printf '%s' '{$encoded}' | base64 --decode > \"\$staged\"",
                'mv -fT -- "$candidate" "$managed"',
                'systemctl daemon-reload',
                'if [ -e /etc/wireguard/orbit.dns-link ] || ! ip link show dev orbit >/dev/null 2>&1; then',
                "resolvectl domain orbit '~orbit'\nresolvectl default-route orbit false\nresolvectl dns orbit 10.44.0.1",
            )
            ->and($command->input)->not->toContain('~.');
    });

    it('verifies the drop-in and a suffix-only, non-default live route', function (
        string $domain,
        string $defaultRoute,
        string $dns,
        bool $dropInMatches,
        string $expected,
    ): void {
        $directory = sys_get_temp_dir().'/orbit-gateway-dns-probe-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $resolver = new GatewayPrivateDnsResolver;
        file_put_contents("{$directory}/drop-in", $dropInMatches ? $resolver->dropIn('10.44.0.1', 'orbit') : "# edited\n");
        file_put_contents("{$directory}/resolvectl", <<<SH
            #!/bin/sh
            case "\$1" in
                domain) printf 'Link 3 (orbit): %s\\n' '{$domain}' ;;
                default-route) printf 'Link 3 (orbit): %s\\n' '{$defaultRoute}' ;;
                dns) printf 'Link 3 (orbit): %s\\n' '{$dns}' ;;
            esac
            SH);
        chmod("{$directory}/resolvectl", 0o755);

        try {
            $command = $resolver->inspectCommand('10.44.0.1', 'orbit');
            $arguments = array_slice($command->arguments, 4);
            $arguments[0] = "{$directory}/drop-in";
            $process = new Process(['bash', '-seu', '--', ...$arguments], env: ['PATH' => "{$directory}:/usr/bin:/bin"]);
            $process->setInput($command->input);
            $process->mustRun();

            expect($command->arguments[0])->toBe('sudo')
                ->and($process->getOutput())->toBe("{$expected}\n");
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    })->with([
        'the managed route' => ['~orbit', 'no', '10.44.0.1', true, '1'],
        'a default DNS route' => ['~orbit', 'yes', '10.44.0.1', true, '0'],
        'a route-everything domain' => ['~.', 'no', '10.44.0.1', true, '0'],
        'no routing domain' => ['', 'no', '10.44.0.1', true, '0'],
        'another DNS server' => ['~orbit', 'no', '10.44.0.9', true, '0'],
        'an edited drop-in' => ['~orbit', 'no', '10.44.0.1', false, '0'],
    ]);

    it('removes the drop-in and reverts only the link it configured', function (): void {
        $command = new GatewayPrivateDnsResolver()->removeCommand();

        expect($command->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
            ->and($command->input)->toContain(
                "if [ ! -e \"\$managed\" ]; then\n    exit 0",
                'rm -f -- "$managed"',
                'systemctl daemon-reload',
                'if [ -e /etc/wireguard/orbit.dns-link ] || ! ip link show dev orbit >/dev/null 2>&1; then',
                'resolvectl revert orbit',
            );
    });

    it('refuses an address or domain that is not a plain value', function (string $address, string $domain): void {
        expect(fn () => new GatewayPrivateDnsResolver()->convergeCommand($address, $domain))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('gateway-private-dns-resolver')
                    ->and($exception->errorCode)->toBe('vpn.configuration_invalid');
            });
    })->with([
        'shell in the address' => ['10.44.0.1; true', 'orbit'],
        'shell in the domain' => ['10.44.0.1', "orbit'; true"],
        'empty domain' => ['10.44.0.1', ''],
    ]);
});
