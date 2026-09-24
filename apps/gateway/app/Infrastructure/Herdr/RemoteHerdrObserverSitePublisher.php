<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Processes\ProtectedInput;
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
        private ObservationGrantSigner $grants,
        private string $configurationRoot = '/etc/orbit/herdr',
        private string $stateRoot = '/var/lib/orbit/herdr-observer',
        private string $systemdDirectory = '/etc/systemd/system',
        private string $versionsDirectory = '/etc/caddy/orbit-versions',
        private string $liveCaddyfilePath = '/etc/caddy/Caddyfile',
        private string $lockPath = CaddyPublicationLock::Path,
        private string $temporaryDirectory = '/run',
        private string $rootOwner = 'root',
        private string $rootGroup = 'root',
        private string $caddyGroup = 'caddy',
        private string $caddyServiceName = 'caddy',
        private string $preferredCaddyExecutable = '/home/linuxbrew/.linuxbrew/bin/caddy',
        private string $phpExecutable = '/usr/bin/php',
        private string $herdrExecutable = HerdrObserveContract::Executable,
    ) {}

    public function publish(HerdrSession $session, Node $node, string $caddyConfiguration): void
    {
        [$certificate, $key] = $this->leaf($session->observer_hostname);
        $observer = file_get_contents(dirname(__DIR__, 3).'/resources/herdr-observer.php');

        if (! is_string($observer)) {
            throw new RuntimeException('Could not load the Herdr observer service.');
        }

        $jwks = json_encode($this->grants->jwks(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->ssh->execute(
            $node,
            $this->command(
                $session,
                $caddyConfiguration,
                $certificate,
                $key,
                $observer,
                $jwks,
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
            $this->command($session, '', '', '', '', '', retract: true),
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
        string $observer,
        string $jwks,
        bool $retract,
    ): RemoteCommand {
        $unit = $this->unit($session);
        $version = 'herdr-'.$session->id.'-'.bin2hex(random_bytes(8));
        $lockScript = CaddyPublicationLock::script();

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $session->session,
                $retract ? 'retract' : 'publish',
                $version,
                $this->configurationRoot,
                $this->stateRoot,
                $this->systemdDirectory,
                $this->versionsDirectory,
                $this->liveCaddyfilePath,
                $this->lockPath,
                $this->temporaryDirectory,
                $this->rootOwner,
                $this->rootGroup,
                $this->caddyGroup,
                $this->caddyServiceName,
                $this->preferredCaddyExecutable,
                $this->phpExecutable,
                $this->herdrExecutable,
            ],
            protectedInput: ProtectedInput::fromString(CaddyGlobalOptions::conflictGuard().<<<BASH
                session=\$1
                action=\$2
                version=\$3
                configuration_root=\$4
                state_root=\$5
                systemd_directory=\$6
                versions=\$7
                live_caddyfile=\$8
                lock=\$9
                temporary_directory=\${10}
                root_owner=\${11}
                root_group=\${12}
                caddy_group=\${13}
                caddy_service=\${14}
                preferred_caddy=\${15}
                php_executable=\${16}
                herdr_executable=\${17}
                umask 0077
                directory=\$configuration_root/\$session
                state_directory=\$state_root/\$session
                owner_marker=\$directory/.orbit-owner
                owner_value=orbit-herdr-observer:\$session
                unit_name=orbit-herdr-observer-\$session.service
                unit=\$systemd_directory/\$unit_name
                owned_fragment=herdr-\$session.caddy
                legacy_fragment=\$versions/current/fragments/\$owned_fragment
                root_uid=\$(id -u "\$root_owner")
                root_gid=\$(getent group "\$root_group" | cut -d: -f3)
                test -n "\$root_gid"
                caddy_gid=\$(getent group "\$caddy_group" | cut -d: -f3)
                test -n "\$caddy_gid"
                {$lockScript}
                if [ -x "\$preferred_caddy" ]; then
                    caddy=\$preferred_caddy
                elif command -v caddy >/dev/null; then
                    caddy=\$(command -v caddy)
                else
                    printf 'caddy is not installed\n' >&2
                    exit 1
                fi
                expected_uid=\$(id -u {$session->user})
                expected_gid=\$(id -g {$session->user})

                source_main=\$(readlink -f "\$live_caddyfile")
                test -f "\$source_main"
                current_fragments=\$(dirname "\$source_main")/fragments
                current_fragment=\$current_fragments/\$owned_fragment
                if [ -e "\$current_fragment" ] || [ -L "\$current_fragment" ]; then
                    test ! -L "\$current_fragment"
                    test -f "\$current_fragment"
                    test "\$(head -n 1 -- "\$current_fragment")" = '# Managed by Orbit: herdr-observer'
                fi
                if [ -e "\$unit" ] || [ -L "\$unit" ]; then
                    test ! -L "\$unit"
                    test -f "\$unit"
                    test "\$(stat -c %u:%g:%a -- "\$unit")" = "\$root_uid:\$root_gid:644"
                    grep -Fxq "X-Orbit-Herdr-Session=\$session" "\$unit"
                fi
                if [ -e "\$directory" ] || [ -L "\$directory" ]; then
                    test ! -L "\$directory"
                    test -d "\$directory"
                    if [ -f "\$owner_marker" ] && [ ! -L "\$owner_marker" ] \
                        && [ "\$(cat -- "\$owner_marker")" = "\$owner_value" ]; then
                        test "\$(stat -c %u:%g:%a -- "\$directory")" = "\$root_uid:\$root_gid:755"
                        test "\$(stat -c %u:%g:%a -- "\$owner_marker")" = "\$root_uid:\$root_gid:644"
                        test -f "\$directory/observer.php" && test ! -L "\$directory/observer.php"
                        test -f "\$directory/jwks.json" && test ! -L "\$directory/jwks.json"
                        test -f "\$directory/cert.pem" && test ! -L "\$directory/cert.pem"
                        test -f "\$directory/key.pem" && test ! -L "\$directory/key.pem"
                        test "\$(stat -c %u:%g:%a -- "\$directory/observer.php")" = "\$root_uid:\$root_gid:755"
                        test "\$(stat -c %u:%g:%a -- "\$directory/jwks.json")" = "\$root_uid:\$root_gid:644"
                        test "\$(stat -c %u:%g:%a -- "\$directory/cert.pem")" = "\$root_uid:\$root_gid:644"
                        test "\$(stat -c %u:%g:%a -- "\$directory/key.pem")" = "\$root_uid:\$caddy_gid:640"
                        unexpected=\$(find "\$directory" -mindepth 1 -maxdepth 1 \
                            ! -name .orbit-owner ! -name observer.php ! -name jwks.json \
                            ! -name cert.pem ! -name key.pem -print -quit)
                        test -z "\$unexpected"
                    else
                        test "\$(stat -c %u:%g:%a -- "\$directory")" = "\$expected_uid:\$expected_gid:750"
                        test -f "\$directory/cert.pem"
                        test ! -L "\$directory/cert.pem"
                        test -f "\$directory/key.pem"
                        test ! -L "\$directory/key.pem"
                        test "\$(stat -c %u:%g:%a -- "\$directory/cert.pem")" = "\$expected_uid:\$expected_gid:644"
                        test "\$(stat -c %u:%g:%a -- "\$directory/key.pem")" = "\$expected_uid:\$expected_gid:600"
                        unexpected=\$(find "\$directory" -mindepth 1 -maxdepth 1 \
                            ! -name cert.pem ! -name key.pem -print -quit)
                        test -z "\$unexpected"
                    fi
                fi
                if [ -e "\$legacy_fragment" ] || [ -L "\$legacy_fragment" ]; then
                    test ! -L "\$legacy_fragment"
                    test -f "\$legacy_fragment"
                    test "\$(head -n 1 -- "\$legacy_fragment")" = '# Managed by Orbit: herdr-observer'
                fi

                if [ "\$action" = retract ] && [ ! -e "\$current_fragment" ] \
                    && [ ! -e "\$legacy_fragment" ] && [ ! -e "\$unit" ] && [ ! -e "\$directory" ]; then
                    exit 0
                fi

                work=\$(mktemp -d "\$temporary_directory/orbit-herdr-observer-\$session.XXXXXX")
                trap 'rm -rf -- "\$work"' EXIT
                candidate="\$versions/\$version.candidate"
                published="\$versions/\$version"
                candidate_link=\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-\$version
                rollback_link=\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-rollback-\$version
                rollback_file=\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-rollback-file-\$version
                previous_main=\$work/previous-main
                previous_target=
                directory_existed=0
                unit_existed=0
                was_enabled=0
                was_active=0
                mutation_started=0
                success=0

                cp -a -- "\$source_main" "\$previous_main"
                if [ -L "\$live_caddyfile" ]; then
                    previous_target=\$(readlink "\$live_caddyfile")
                fi
                if [ -d "\$directory" ]; then
                    cp -a -- "\$directory" "\$work/directory"
                    directory_existed=1
                fi
                if [ -f "\$unit" ]; then
                    cp -a -- "\$unit" "\$work/unit"
                    unit_existed=1
                fi
                if systemctl is-enabled --quiet "\$unit_name" 2>/dev/null; then
                    was_enabled=1
                fi
                if systemctl is-active --quiet "\$unit_name" 2>/dev/null; then
                    was_active=1
                fi

                rollback() {
                    set +e
                    if [ -n "\$previous_target" ]; then
                        ln -s -- "\$previous_target" "\$rollback_link"
                        mv -fT -- "\$rollback_link" "\$live_caddyfile"
                    else
                        cp -a -- "\$previous_main" "\$rollback_file"
                        mv -fT -- "\$rollback_file" "\$live_caddyfile"
                    fi
                    systemctl disable --now "\$unit_name" >/dev/null 2>&1
                    rm -f -- "\$unit"
                    rm -rf -- "\$directory"
                    if [ "\$directory_existed" = 1 ]; then
                        cp -a -- "\$work/directory" "\$directory"
                    fi
                    if [ "\$unit_existed" = 1 ]; then
                        cp -a -- "\$work/unit" "\$unit"
                    fi
                    systemctl daemon-reload
                    if [ "\$was_enabled" = 1 ]; then
                        systemctl enable "\$unit_name" >/dev/null 2>&1
                    fi
                    if [ "\$was_active" = 1 ]; then
                        systemctl restart "\$unit_name" >/dev/null 2>&1
                    fi
                    systemctl reload-or-restart "\$caddy_service" >/dev/null 2>&1
                    rm -rf -- "\$published"
                }
                finish() {
                    status=\$?
                    trap - EXIT
                    if [ "\$mutation_started" = 1 ] && [ "\$success" != 1 ]; then
                        rollback
                    fi
                    rm -rf -- "\$candidate" "\$work"
                    rm -f -- "\$candidate_link" "\$rollback_link" "\$rollback_file"
                    exit "\$status"
                }
                trap finish EXIT

                install -d -o "\$root_owner" -g "\$caddy_group" -m 0750 -- "\$versions" "\$candidate/fragments"
                preserve_source_main=1
                case "\$source_main" in
                    "\$versions"/*/Caddyfile)
                        for fragment in "\$current_fragments"/*.caddy; do
                            fragment_name=\$(basename "\$fragment")
                            if [ ! -e "\$fragment" ] || [ "\$fragment_name" = "\$owned_fragment" ]; then
                                continue
                            fi
                            destination="\$candidate/fragments/\$fragment_name"
                            if [ "\$fragment_name" = unmanaged.caddy ]; then
                                destination="\$candidate/fragments/00-unmanaged.caddy"
                                test ! -e "\$destination"
                            fi
                            cp --preserve=mode,ownership -- "\$fragment" "\$destination"
                        done
                        ;;
                    *)
                        if [ "\$source_main" = "\$live_caddyfile" ]; then
                            current_md5=\$(md5sum -- "\$source_main" | awk '{print \$1}')
                            default_md5=\$(dpkg-query -W -f='\${Conffiles}\n' "\$caddy_service" | \
                                awk -v live_caddyfile="\$live_caddyfile" '\$1 == live_caddyfile { print \$2; exit }')
                            if [ -n "\$default_md5" ] && [ "\$current_md5" = "\$default_md5" ]; then
                                preserve_source_main=0
                            fi
                        fi
                        if [ "\$preserve_source_main" = 1 ]; then
                            cp --preserve=mode,ownership -- "\$source_main" "\$candidate/fragments/00-unmanaged.caddy"
                        fi
                        ;;
                esac

                if [ "\$action" = publish ]; then
                    printf '%s' '{$this->encode($observer)}' | base64 --decode > "\$work/observer.php"
                    printf '%s' '{$this->encode($jwks)}' | base64 --decode > "\$work/jwks.json"
                    printf '%s' '{$this->encode($certificate)}' | base64 --decode > "\$work/cert.pem"
                    printf '%s' '{$this->encode($key)}' | base64 --decode > "\$work/key.pem"
                    printf '%s' '{$this->encode($unit)}' | base64 --decode > "\$work/\$unit_name"
                    printf '%s' "\$owner_value" > "\$work/owner"
                    printf '%s' '{$this->encode($caddyConfiguration)}' | base64 --decode > "\$candidate/fragments/\$owned_fragment"
                    chmod 0755 -- "\$work/observer.php"
                    chmod 0644 -- "\$work/jwks.json" "\$work/cert.pem" "\$work/owner" "\$work/\$unit_name"
                    chmod 0640 -- "\$work/key.pem"
                    "\$php_executable" -l "\$work/observer.php" >/dev/null
                    openssl x509 -in "\$work/cert.pem" -noout
                    openssl pkey -in "\$work/key.pem" -check -noout
                    test "\$(openssl x509 -in "\$work/cert.pem" -pubkey -noout | sha256sum)" = \
                        "\$(openssl pkey -in "\$work/key.pem" -pubout | sha256sum)"
                    systemd-analyze verify "\$work/\$unit_name"
                    mutation_started=1
                    install -d -o "\$root_owner" -g "\$root_group" -m 0755 -- "\$configuration_root" "\$state_root" "\$directory"
                    install -d -o {$session->user} -g {$session->user} -m 0700 -- "\$state_directory"
                    install -o "\$root_owner" -g "\$root_group" -m 0755 -- "\$work/observer.php" "\$directory/observer.php.new"
                    install -o "\$root_owner" -g "\$root_group" -m 0644 -- "\$work/jwks.json" "\$directory/jwks.json.new"
                    install -o "\$root_owner" -g "\$root_group" -m 0644 -- "\$work/cert.pem" "\$directory/cert.pem.new"
                    install -o "\$root_owner" -g "\$caddy_group" -m 0640 -- "\$work/key.pem" "\$directory/key.pem.new"
                    install -o "\$root_owner" -g "\$root_group" -m 0644 -- "\$work/owner" "\$owner_marker.new"
                    install -o "\$root_owner" -g "\$root_group" -m 0644 -- "\$work/\$unit_name" "\$unit.new"
                    mv -f -- "\$directory/observer.php.new" "\$directory/observer.php"
                    mv -f -- "\$directory/jwks.json.new" "\$directory/jwks.json"
                    mv -f -- "\$directory/cert.pem.new" "\$directory/cert.pem"
                    mv -f -- "\$directory/key.pem.new" "\$directory/key.pem"
                    mv -f -- "\$owner_marker.new" "\$owner_marker"
                    mv -f -- "\$unit.new" "\$unit"
                fi

                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/fragments/*.caddy\n' "\$candidate" >> "\$candidate/Caddyfile"
                chown -R "\$root_owner:\$caddy_group" "\$candidate"
                find "\$candidate" -type d -exec chmod 0750 {} +
                find "\$candidate" -type f -exec chmod 0640 {} +
                refuse_carried_global_options "\$candidate" "\$source_main"
                "\$caddy" validate --config "\$candidate/Caddyfile" --adapter caddyfile
                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" >> "\$candidate/Caddyfile"
                mutation_started=1
                mv -fT -- "\$candidate" "\$published"
                ln -s -- "\$published/Caddyfile" "\$candidate_link"
                mv -fT -- "\$candidate_link" "\$live_caddyfile"

                if [ "\$action" = publish ]; then
                    systemctl daemon-reload
                    systemctl enable "\$unit_name" >/dev/null
                    systemctl restart "\$unit_name"
                    systemctl reload-or-restart "\$caddy_service"
                else
                    systemctl reload-or-restart "\$caddy_service"
                    if [ -f "\$unit" ]; then
                        systemctl disable --now "\$unit_name" >/dev/null
                    fi
                    rm -f -- "\$unit"
                    systemctl daemon-reload
                    systemctl reset-failed "\$unit_name" >/dev/null 2>&1 || true
                    rm -rf -- "\$directory"
                fi
                rm -f -- "\$legacy_fragment"
                success=1
                BASH),
        );
    }

    private function unit(HerdrSession $session): string
    {
        $directory = $this->configurationRoot.'/'.$session->session;
        $stateDirectory = $this->stateRoot.'/'.$session->session;
        $listen = '127.0.0.1:'.$session->observer_port;
        $processUnit = $session->process_id === null
            ? 'network.target'
            : "orbit-process-{$session->process_id}-herdr-{$session->session}.service";

        return <<<UNIT
            [Unit]
            Description=Orbit receive-only Herdr observer ({$session->session})
            X-Orbit-Herdr-Session={$session->session}
            After=network.target {$processUnit}

            [Service]
            Type=simple
            User={$session->user}
            Group={$session->user}
            ExecStart={$this->phpExecutable} {$directory}/observer.php --listen={$listen} --node={$session->node->name} --session={$session->session} --jwks={$directory}/jwks.json --nonce-store={$stateDirectory}/nonces.json --herdr={$this->herdrExecutable}
            Restart=on-failure
            RestartSec=2s
            KillMode=control-group
            TimeoutStopSec=10s
            UMask=0077
            NoNewPrivileges=true
            PrivateDevices=true
            PrivateTmp=true
            ProtectControlGroups=true
            ProtectHome=read-only
            ProtectKernelModules=true
            ProtectKernelTunables=true
            ProtectSystem=strict
            ReadWritePaths={$stateDirectory}
            RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
            RestrictSUIDSGID=true

            [Install]
            WantedBy=multi-user.target
            UNIT;
    }

    private function encode(string $value): string
    {
        return base64_encode($value);
    }

    private function encodedGlobalOptions(): string
    {
        return base64_encode(CaddyGlobalOptions::render());
    }
}
